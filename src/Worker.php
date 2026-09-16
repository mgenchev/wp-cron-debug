<?php

namespace WpCronDebug;

final class Worker {
    private const PROFILE_QUERY_LIMIT = 10;
    private const PROFILE_QUERY_TEXT_LIMIT = 2000;

    private $requestFile;
    private $resultFile;
    private $startedAt;
    private $started;
    private $completed = false;
    private $status = 'exited';
    private $fatal = false;
    private $fatalMessage = '';
    private $previousErrorHandler;
    private $mode = 'debug';
    private $profileStartQueries = 0;
    private $profileStartNumQueries = 0;
    private $profileStartMemory = 0;
    private $profileEnabled = false;
    private $lifecycle = array();

    public function __construct( $requestFile, $resultFile ) {
        $this->requestFile = $requestFile;
        $this->resultFile = $resultFile;
    }

    public function run() {
        $request = $this->readRequest();
        $this->mode = isset( $request['mode'] ) ? (string) $request['mode'] : 'debug';
        if ( ! in_array( $this->mode, array( 'debug', 'profile', 'real' ), true ) ) {
            throw new \RuntimeException( 'Worker execution mode is invalid.' );
        }

        $this->started = microtime( true );
        $this->startedAt = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        register_shutdown_function( array( $this, 'shutdown' ) );

        $event = isset( $request['event'] ) && is_array( $request['event'] ) ? $request['event'] : array();
        $this->validateEventRequest( $event );

        if ( ! EventMatcher::exists( $event['hook'], $event['args'], (int) $event['timestamp'] ) ) {
            $this->status = 'missing_event';
            $this->completed = true;
            $this->writeMeta();
            return;
        }

        global $wp_filter;
        if ( ! isset( $wp_filter[ $event['hook'] ] ) ) {
            $this->status = 'no_callback';
            $this->completed = true;
            $this->writeMeta();
            return;
        }

        if ( ! defined( 'DOING_CRON' ) ) {
            define( 'DOING_CRON', true );
        }

        if ( 'profile' === $this->mode ) {
            $this->startProfile();
        }

        if ( 'real' === $this->mode ) {
            $this->applyRealCronLifecycle( $event );
        }

        $this->previousErrorHandler = set_error_handler( array( $this, 'handleError' ) );
        try {
            do_action_ref_array( $event['hook'], $event['args'] );
            $this->status = 'success';
            $this->completed = true;
        } catch ( \Throwable $e ) {
            $this->status = 'exception';
            $this->fatal = false;
            $this->fatalMessage = get_class( $e ) . ': ' . $e->getMessage();
            fwrite( STDERR, $this->fatalMessage . PHP_EOL . $e->getTraceAsString() . PHP_EOL );
            $this->completed = true;
        } finally {
            restore_error_handler();
        }

        $this->writeMeta();
    }

    public function handleError( $severity, $message, $file, $line ) {
        if ( 0 === ( error_reporting() & $severity ) ) {
            return false;
        }

        fwrite( STDERR, sprintf( 'PHP error [%d]: %s in %s:%d%s', $severity, $message, $file, $line, PHP_EOL ) );

        if ( is_callable( $this->previousErrorHandler ) ) {
            return (bool) call_user_func( $this->previousErrorHandler, $severity, $message, $file, $line );
        }

        return true;
    }

    public function shutdown() {
        $lastError = error_get_last();
        if ( is_array( $lastError ) && in_array( $lastError['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
            $this->fatal = true;
            $this->status = 'fatal';
            $this->fatalMessage = $lastError['message'] . ' in ' . $lastError['file'] . ':' . $lastError['line'];
        }

        $this->writeMeta();
    }

    private function startProfile() {
        global $wpdb;

        $this->profileEnabled = true;
        $this->profileStartMemory = memory_get_usage( true );
        $this->profileStartNumQueries = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
        $this->profileStartQueries = isset( $wpdb->queries ) && is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
    }

    private function buildProfileMeta() {
        if ( ! $this->profileEnabled ) {
            return array();
        }

        global $wpdb;

        $currentNumQueries = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : $this->profileStartNumQueries;
        $queryCount = max( 0, $currentNumQueries - $this->profileStartNumQueries );
        $queryRows = array();
        $queryTime = 0.0;

        if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
            $captured = array_slice( $wpdb->queries, $this->profileStartQueries );
            foreach ( $captured as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                $sql = isset( $entry[0] ) ? (string) $entry[0] : '';
                $duration = isset( $entry[1] ) ? (float) $entry[1] : 0.0;
                $caller = isset( $entry[2] ) ? (string) $entry[2] : '';
                $queryTime += $duration;
                $queryRows[] = array(
                    'sql' => $this->truncateText( $sql, self::PROFILE_QUERY_TEXT_LIMIT ),
                    'duration' => $duration,
                    'caller' => $this->truncateText( $caller, 500 ),
                );
            }
        }

        usort( $queryRows, static function ( $a, $b ) {
            if ( $a['duration'] === $b['duration'] ) {
                return 0;
            }
            return $a['duration'] > $b['duration'] ? -1 : 1;
        } );

        if ( count( $queryRows ) > self::PROFILE_QUERY_LIMIT ) {
            $queryRows = array_slice( $queryRows, 0, self::PROFILE_QUERY_LIMIT );
        }

        $memoryEnd = memory_get_usage( true );

        return array(
            'query_count' => $queryCount,
            'query_time' => $queryTime,
            'query_details_available' => defined( 'SAVEQUERIES' ) && SAVEQUERIES && isset( $wpdb->queries ) && is_array( $wpdb->queries ),
            'queries' => $queryRows,
            'memory_start' => $this->profileStartMemory,
            'memory_end' => $memoryEnd,
            'memory_delta' => $memoryEnd - $this->profileStartMemory,
        );
    }

    private function applyRealCronLifecycle( array $event ) {
        if ( ! function_exists( 'wp_unschedule_event' ) ) {
            throw new \RuntimeException( 'wp_unschedule_event() is unavailable.' );
        }

        $schedule = isset( $event['schedule'] ) ? $event['schedule'] : false;
        if ( function_exists( 'wp_get_scheduled_event' ) ) {
            $currentEvent = wp_get_scheduled_event( $event['hook'], $event['args'], (int) $event['timestamp'] );
            if ( is_object( $currentEvent ) && property_exists( $currentEvent, 'schedule' ) ) {
                $schedule = $currentEvent->schedule;
            }
        }
        if ( false !== $schedule && '' !== (string) $schedule ) {
            if ( ! function_exists( 'wp_reschedule_event' ) ) {
                throw new \RuntimeException( 'wp_reschedule_event() is unavailable.' );
            }

            $rescheduled = wp_reschedule_event( (int) $event['timestamp'], (string) $schedule, $event['hook'], $event['args'] );
            $this->lifecycle['rescheduled'] = $this->normalizeLifecycleResult( $rescheduled );
        } else {
            $this->lifecycle['rescheduled'] = 'not_applicable';
        }

        $unscheduled = wp_unschedule_event( (int) $event['timestamp'], $event['hook'], $event['args'] );
        $this->lifecycle['unscheduled'] = $this->normalizeLifecycleResult( $unscheduled );
    }

    private function normalizeLifecycleResult( $result ) {
        if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
            return 'error';
        }
        return $result ? 'success' : 'failed';
    }

    private function writeMeta() {
        $meta = array(
            'status' => $this->status,
            'completed' => $this->completed,
            'fatal' => $this->fatal,
            'fatal_message' => $this->fatalMessage,
            'started_at' => $this->startedAt,
            'duration' => $this->started ? microtime( true ) - $this->started : 0,
            'peak_memory' => memory_get_peak_usage( true ),
            'mode' => $this->mode,
            'profile' => $this->buildProfileMeta(),
            'lifecycle' => $this->lifecycle,
        );

        @file_put_contents( $this->resultFile, json_encode( $meta ), LOCK_EX );
    }

    private function readRequest() {
        if ( ! is_readable( $this->requestFile ) ) {
            throw new \RuntimeException( 'Worker request file is not readable.' );
        }

        $raw = file_get_contents( $this->requestFile );
        $request = json_decode( (string) $raw, true );
        if ( ! is_array( $request ) ) {
            throw new \RuntimeException( 'Worker request file is invalid.' );
        }
        return $request;
    }

    private function validateEventRequest( array $event ) {
        if ( empty( $event['hook'] ) || ! isset( $event['timestamp'] ) || ! isset( $event['args'] ) || ! is_array( $event['args'] ) ) {
            throw new \RuntimeException( 'Worker event request is incomplete.' );
        }
    }

    private function truncateText( $text, $limit ) {
        $text = (string) $text;
        if ( strlen( $text ) <= $limit ) {
            return $text;
        }
        return substr( $text, 0, max( 0, $limit - 15 ) ) . '...[truncated]';
    }
}
