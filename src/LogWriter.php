<?php

namespace WpCronDebug;

final class LogWriter {
    private const SEPARATOR = '--------------------------------------------------------------------';

    private $path;

    public function __construct( $workingDirectory ) {
        $workingDirectory = rtrim( (string) $workingDirectory, '/\\' );
        if ( '' === $workingDirectory || ! is_dir( $workingDirectory ) ) {
            throw new \RuntimeException( 'Unable to determine the command working directory.' );
        }
        if ( ! is_writable( $workingDirectory ) ) {
            throw new \RuntimeException( 'The command working directory is not writable: ' . $workingDirectory );
        }

        $this->path = $workingDirectory . DIRECTORY_SEPARATOR . 'cron-debug.log';
        if ( file_exists( $this->path ) && ! is_writable( $this->path ) ) {
            throw new \RuntimeException( 'The log file is not writable: ' . $this->path );
        }
    }

    public function getPath() {
        return $this->path;
    }

    public function write( array $event, array $result, array $workerMeta ) {
        $status = isset( $workerMeta['status'] ) ? strtoupper( (string) $workerMeta['status'] ) : 'UNKNOWN';
        if ( ! empty( $result['timed_out'] ) ) {
            $status = 'TIMEOUT';
        } elseif ( isset( $workerMeta['fatal'] ) && $workerMeta['fatal'] ) {
            $status = 'FATAL';
        } elseif ( 0 !== (int) $result['exit_code'] && 'SUCCESS' === $status ) {
            $status = 'FAILED';
        }

        $callbacks = array();
        foreach ( $event['callbacks'] as $callback ) {
            $callbacks[] = $callback['name'];
        }

        $mode = isset( $workerMeta['mode'] ) ? (string) $workerMeta['mode'] : 'debug';
        $duration = isset( $workerMeta['duration'] ) ? (float) $workerMeta['duration'] : (float) $result['duration'];
        $peakMemory = isset( $workerMeta['peak_memory'] ) ? (int) $workerMeta['peak_memory'] : 0;

        $lines = array(
            'WP CRON DEBUG',
            self::SEPARATOR,
            '',
            'Hook:       ' . $event['hook'],
            'Category:   ' . $this->categoryLabel( $event['category'] ),
            'Source:     ' . $event['source'],
            'Callback:   ' . ( empty( $callbacks ) ? 'No registered callback' : implode( ', ', $callbacks ) ),
            'Schedule:   ' . ( $event['schedule'] ? $event['schedule'] : 'single' ),
            'Arguments:  ' . $this->encodeJson( $event['args'] ),
            'Started:    ' . ( isset( $workerMeta['started_at'] ) ? $workerMeta['started_at'] : gmdate( 'Y-m-d H:i:s' ) . ' UTC' ),
            'Mode:       ' . $this->modeLabel( $mode ),
            '',
            'RESULT',
            self::SEPARATOR,
            '',
            'Status:      ' . $status,
            'Duration:    ' . number_format( $duration, 3, '.', '' ) . ' s',
        );

        if ( $peakMemory > 0 ) {
            $lines[] = 'Peak memory: ' . $this->formatBytes( $peakMemory );
        }

        $lines[] = '';
        $lines[] = 'OUTPUT';
        $lines[] = self::SEPARATOR;
        $lines[] = '';

        $stdout = rtrim( (string) $result['stdout'] );
        $stderr = rtrim( (string) $result['stderr'] );

        $lines[] = '' === $stdout ? '[no stdout output]' : $stdout;

        if ( '' !== $stderr ) {
            $lines[] = '';
            $lines[] = 'ERROR OUTPUT';
            $lines[] = self::SEPARATOR;
            $lines[] = '';
            $lines[] = $stderr;
        }

        if ( ! empty( $result['truncated'] ) ) {
            $lines[] = '';
            $lines[] = '[output truncated after 10 MB]';
        }

        if ( ! empty( $workerMeta['fatal_message'] ) && false === strpos( $stderr, $workerMeta['fatal_message'] ) ) {
            $lines[] = '';
            $lines[] = 'Fatal: ' . $workerMeta['fatal_message'];
        }

        if ( 'profile' === $mode ) {
            $this->appendProfileSection( $lines, isset( $workerMeta['profile'] ) && is_array( $workerMeta['profile'] ) ? $workerMeta['profile'] : array() );
        }

        if ( 'real' === $mode ) {
            $this->appendLifecycleSection( $lines, isset( $workerMeta['lifecycle'] ) && is_array( $workerMeta['lifecycle'] ) ? $workerMeta['lifecycle'] : array() );
        }

        $lines[] = '';

        $content = implode( PHP_EOL, $lines );
        if ( false === file_put_contents( $this->path, $content, LOCK_EX ) ) {
            throw new \RuntimeException( 'Unable to write log file: ' . $this->path );
        }
    }

    private function appendProfileSection( array &$lines, array $profile ) {
        $lines[] = '';
        $lines[] = 'PROFILE';
        $lines[] = self::SEPARATOR;
        $lines[] = '';

        $queryCount = isset( $profile['query_count'] ) ? (int) $profile['query_count'] : 0;
        $queryTime = isset( $profile['query_time'] ) ? (float) $profile['query_time'] : 0.0;
        $memoryDelta = isset( $profile['memory_delta'] ) ? (int) $profile['memory_delta'] : 0;
        $detailsAvailable = ! empty( $profile['query_details_available'] );

        $lines[] = 'SUMMARY';
        $lines[] = '';
        $lines[] = 'DB queries:   ' . $queryCount;
        $lines[] = 'DB time:      ' . number_format( $queryTime, 4, '.', '' ) . ' s';
        $lines[] = 'Memory delta: ' . $this->formatSignedBytes( $memoryDelta );

        if ( ! $detailsAvailable ) {
            $lines[] = '';
            $lines[] = 'TOP 10 QUERIES BY DURATION';
            $lines[] = '';
            $lines[] = '[query details unavailable: SAVEQUERIES was not active before WordPress loaded]';
            return;
        }

        $queries = isset( $profile['queries'] ) && is_array( $profile['queries'] ) ? $profile['queries'] : array();
        $lines[] = '';
        $lines[] = 'TOP 10 QUERIES BY DURATION';
        $lines[] = '';

        if ( empty( $queries ) ) {
            $lines[] = '[none captured]';
            return;
        }

        foreach ( $queries as $index => $query ) {
            $duration = isset( $query['duration'] ) ? (float) $query['duration'] : 0.0;
            $sql = isset( $query['sql'] ) ? trim( (string) $query['sql'] ) : '';
            $caller = isset( $query['caller'] ) ? trim( (string) $query['caller'] ) : '';

            $lines[] = sprintf( '[%d]  %.4f s', $index + 1, $duration );
            $lines[] = '    Caller: ' . ( '' === $caller ? '[unknown]' : $caller );
            $lines[] = '    Query:  ' . ( '' === $sql ? '[query unavailable]' : $sql );

            if ( $index < count( $queries ) - 1 ) {
                $lines[] = '';
            }
        }
    }

    private function appendLifecycleSection( array &$lines, array $lifecycle ) {
        $lines[] = '';
        $lines[] = 'CRON LIFECYCLE';
        $lines[] = self::SEPARATOR;
        $lines[] = '';
        $lines[] = 'Rescheduled: ' . $this->lifecycleLabel( isset( $lifecycle['rescheduled'] ) ? $lifecycle['rescheduled'] : 'unknown' );
        $lines[] = 'Unscheduled: ' . $this->lifecycleLabel( isset( $lifecycle['unscheduled'] ) ? $lifecycle['unscheduled'] : 'unknown' );
    }

    private function lifecycleLabel( $status ) {
        $labels = array(
            'success' => 'yes',
            'failed' => 'no',
            'error' => 'error',
            'not_applicable' => 'not applicable',
            'unknown' => 'unknown',
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
    }

    private function modeLabel( $mode ) {
        if ( 'profile' === $mode ) {
            return 'Profile run / schedule preserved';
        }
        if ( 'real' === $mode ) {
            return 'Real cron lifecycle / schedule updated';
        }
        return 'Debug run / schedule preserved';
    }

    private function encodeJson( $value ) {
        $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return false === $json ? '[unserializable arguments]' : $json;
    }

    private function categoryLabel( $category ) {
        $labels = array(
            SourceClassifier::CORE => 'WordPress Core',
            SourceClassifier::PLUGINS => 'Plugins',
            SourceClassifier::THEME => 'Theme',
            SourceClassifier::OTHER => 'Other',
        );
        return isset( $labels[ $category ] ) ? $labels[ $category ] : 'Other';
    }

    private function formatBytes( $bytes ) {
        $units = array( 'B', 'KB', 'MB', 'GB' );
        $value = (float) $bytes;
        $index = 0;
        while ( abs( $value ) >= 1024 && $index < count( $units ) - 1 ) {
            $value /= 1024;
            $index++;
        }
        return number_format( $value, $index === 0 ? 0 : 1 ) . ' ' . $units[ $index ];
    }

    private function formatSignedBytes( $bytes ) {
        $bytes = (int) $bytes;
        if ( 0 === $bytes ) {
            return '0 B';
        }
        return ( $bytes > 0 ? '+' : '-' ) . $this->formatBytes( abs( $bytes ) );
    }
}
