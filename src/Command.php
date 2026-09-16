<?php

namespace WpCronDebug;

/**
 * Interactively inspect and debug scheduled WP-Cron events.
 *
 * ## OPTIONS
 *
 * [<hook>]
 * : Run a specific scheduled hook directly. If multiple instances exist, choose one interactively.
 *
 * [--timeout=<seconds>]
 * : Maximum time allowed for an isolated cron run. Default: 300.
 *
 * @when after_wp_load
 */
final class Command {
    private const DEFAULT_TIMEOUT = 300;
    private const MAX_TIMEOUT = 86400;

    private $console;
    private $repository;
    private $processRunner;
    private $diagnostics;
    private $workingDirectory;
    private $timeout;
    private $flashMessage = '';

    public function __invoke( $args, $assocArgs ) {
        $workerRequest = getenv( 'WP_CRON_DEBUG_WORKER_REQUEST' );
        $workerResult = getenv( 'WP_CRON_DEBUG_WORKER_RESULT' );
        if ( false !== $workerRequest || false !== $workerResult ) {
            $this->runWorkerMode( $workerRequest, $workerResult );
            return;
        }

        $this->console = new Console();
        $this->repository = new EventRepository( SourceClassifier::fromWordPress() );
        $this->processRunner = new ProcessRunner();
        $this->diagnostics = new EventDiagnostics();
        $this->workingDirectory = getcwd();
        $this->timeout = $this->parseTimeout( isset( $assocArgs['timeout'] ) ? $assocArgs['timeout'] : self::DEFAULT_TIMEOUT );

        try {
            if ( ! empty( $args ) ) {
                if ( count( $args ) > 1 ) {
                    throw new \RuntimeException( 'Only one hook may be specified.' );
                }

                $this->runDirectHook( (string) $args[0] );
                return;
            }

            $this->runInteractive();
        } catch ( ExitRequested $e ) {
            $this->console->line();
            return;
        } catch ( \RuntimeException $e ) {
            \WP_CLI::error( $e->getMessage() );
        }
    }

    private function runInteractive() {
        while ( true ) {
            $groups = $this->repository->allGroupedByCategory();
            $categoryChoice = $this->chooseCategory( $groups );
            $this->runCategory( $categoryChoice, $groups[ $categoryChoice ] );
        }
    }

    private function runDirectHook( $hook ) {
        $hook = trim( $hook );
        if ( '' === $hook ) {
            throw new \RuntimeException( 'Hook name cannot be empty.' );
        }

        $events = $this->repository->eventsForHook( $hook );
        if ( empty( $events ) ) {
            throw new \RuntimeException( 'No scheduled event found for hook "' . $hook . '".' );
        }

        $event = count( $events ) > 1 ? $this->chooseInstance( $events ) : $events[0];
        if ( null === $event ) {
            return;
        }

        $result = $this->inspectEventFlow( $event );
        if ( 'real_complete' === $result ) {
            $groups = $this->repository->allGroupedByCategory();
            $category = $event['category'];
            $this->runCategory( $category, isset( $groups[ $category ] ) ? $groups[ $category ] : array() );
        }
    }

    private function chooseCategory( array $groups ) {
        $choices = array(
            '1' => 'WordPress Core',
            '2' => 'Plugins',
            '3' => 'Theme',
            '4' => 'Other',
        );
        $map = array(
            '1' => SourceClassifier::CORE,
            '2' => SourceClassifier::PLUGINS,
            '3' => SourceClassifier::THEME,
            '4' => SourceClassifier::OTHER,
        );
        $columns = array();
        foreach ( $map as $key => $category ) {
            $columns[ $key ] = $this->countEvents( $groups[ $category ] ) . ' events';
        }

        $this->console->clearScreen();
        $selected = $this->console->promptMenu( 'Cron Debug', $choices, $columns, false );
        return $map[ $selected ];
    }

    private function runCategory( $category, array $hooks ) {
        while ( true ) {
            if ( empty( $hooks ) ) {
                $this->console->clearScreen();
                $this->renderFlashMessage();
                $this->console->line();
                $this->console->line( 'No scheduled events in this category.' );
                $choice = $this->console->promptMenu( 'Options', array(), array(), true );
                if ( '0' === $choice ) {
                    return;
                }
            }

            $choices = array();
            $columns = array();
            $hookMap = array();
            $summaries = $this->buildHookSummaries( $hooks );
            $index = 1;

            foreach ( $hooks as $hook => $events ) {
                $key = (string) $index++;
                $choices[ $key ] = $hook;
                $hookMap[ $key ] = $events;
                $status = $this->hookDiagnosticStatus( $events );
                $columns[ $key ] = $this->formatStatusColumn( $status ) . $summaries[ $hook ];
            }

            $this->console->clearScreen();
            $this->renderFlashMessage();
            $selected = $this->console->promptMenu( $this->categoryLabel( $category ), $choices, $columns, true );
            if ( '0' === $selected ) {
                return;
            }

            $events = $hookMap[ $selected ];
            $event = count( $events ) > 1 ? $this->chooseInstance( $events ) : $events[0];
            if ( null === $event ) {
                $groups = $this->repository->allGroupedByCategory();
                $hooks = $groups[ $category ];
                continue;
            }

            $this->inspectEventFlow( $event );
            $groups = $this->repository->allGroupedByCategory();
            $hooks = $groups[ $category ];
        }
    }

    private function chooseInstance( array $events ) {
        $choices = array();
        $columns = array();
        $map = array();
        foreach ( $events as $index => $event ) {
            $key = (string) ( $index + 1 );
            $choices[ $key ] = 'Args: ' . $this->shortJson( $event['args'] );
            $columns[ $key ] = $this->relativeTime( $event['timestamp'] ) . ' · ' . $this->scheduleLabel( $event );
            $map[ $key ] = $event;
        }

        $this->console->clearScreen();
        $selected = $this->console->promptMenu( $events[0]['hook'], $choices, $columns, true );
        if ( '0' === $selected ) {
            return null;
        }
        return $map[ $selected ];
    }

    private function inspectEventFlow( array $event ) {
        $this->console->clearScreen();
        $this->showEventDetails( $event );
        $choice = $this->console->promptDetailsResult();
        if ( '0' === $choice ) {
            return;
        }

        $mode = '1' === $choice ? 'debug' : ( '2' === $choice ? 'profile' : 'real' );
        return $this->runEventFlow( $event, $mode );
    }

    private function showEventDetails( array $event ) {
        $this->console->line();
        $this->console->line( 'Cron Event Details' );

        $this->console->section( 'EVENT' );
        $this->console->line( 'Hook:       ' . $event['hook'] );
        $this->console->line( 'Category:   ' . $this->categoryLabel( $event['category'] ) );
        $this->console->line( 'Source:     ' . $event['source'] );
        $this->console->line( 'Schedule:   ' . $this->scheduleLabel( $event ) );
        $this->console->line( 'Next run:   ' . gmdate( 'Y-m-d H:i:s', (int) $event['timestamp'] ) . ' UTC (' . $this->relativeTime( $event['timestamp'] ) . ')' );
        $this->console->line( 'Arguments:  ' . $this->fullJson( $event['args'] ) );

        $this->console->section( 'CALLBACK' );
        if ( empty( $event['callbacks'] ) ) {
            $this->console->line( 'Callback:   (none registered)' );
        }

        foreach ( $event['callbacks'] as $index => $callback ) {
            if ( $index > 0 ) {
                $this->console->line();
            }

            $label = count( $event['callbacks'] ) > 1 ? 'Callback ' . ( $index + 1 ) . ':' : 'Callback:';
            $this->console->line( str_pad( $label, 12, ' ' ) . $callback['name'] );

            $file = isset( $callback['file'] ) ? (string) $callback['file'] : '';
            $line = isset( $callback['line'] ) ? (int) $callback['line'] : 0;
            if ( '' !== $file ) {
                $location = $file . ( $line > 0 ? ':' . $line : '' );
                $this->console->line( 'File:       ' . $location );
            } else {
                $this->console->line( 'File:       (unknown)' );
            }

            $this->console->line( 'Priority:   ' . (int) $callback['priority'] );
            $this->console->line( 'Callable:   ' . ( ! empty( $callback['callable'] ) ? 'yes' : 'no' ) );
        }

        $this->console->section( 'DIAGNOSTICS' );
        $diagnostics = $this->diagnostics instanceof EventDiagnostics ? $this->diagnostics : new EventDiagnostics();
        foreach ( $diagnostics->analyze( $event ) as $diagnostic ) {
            if ( EventDiagnostics::ERROR === $diagnostic['level'] ) {
                $this->console->line( $this->console->statusText( 'error', '✗ ERROR' ) . '  ' . $diagnostic['message'] );
            } elseif ( EventDiagnostics::WARNING === $diagnostic['level'] ) {
                $this->console->line( $this->console->statusText( 'warning', '! WARN' ) . '   ' . $diagnostic['message'] );
            } else {
                $this->console->line( $this->console->statusText( 'ok', '✓ OK' ) . '     ' . $diagnostic['message'] );
            }
        }
    }

    private function runEventFlow( array $event, $mode ) {
        while ( true ) {
            $this->console->clearScreen();
            $this->console->line();
            $this->console->line( 'Selected: ' . $event['hook'] );
            $this->console->line( 'Mode: ' . $this->executionModeLabel( $mode ) );
            $result = $this->executeEvent( $event, $mode );

            if ( $result['success'] ) {
                $this->console->success( 'Cron completed successfully.' );
            } elseif ( 'timeout' === $result['status'] ) {
                $this->console->clearSpinner();
                $this->console->errorLine( 'Cron timed out after ' . $this->timeout . ' seconds.' );
            } elseif ( 'missing_event' === $result['status'] ) {
                $this->console->clearSpinner();
                $this->console->errorLine( 'The scheduled event no longer exists.' );
            } elseif ( 'no_callback' === $result['status'] ) {
                $this->console->clearSpinner();
                $this->console->errorLine( 'The event has no registered callback.' );
            } elseif ( 'exited' === $result['status'] ) {
                $this->console->clearSpinner();
                $this->console->warning( 'Cron stopped via exit/die.' );
            } else {
                $this->console->clearSpinner();
                $this->console->errorLine( 'Cron failed. See cron-debug.log for details.' );
            }

            if ( 'profile' === $mode && ! empty( $result['profile'] ) ) {
                $profile = $result['profile'];
                $this->console->line(
                    'Profile: ' . (int) $profile['query_count'] . ' queries · '
                    . number_format( (float) $profile['query_time'], 4, '.', '' ) . ' s DB · '
                    . $this->formatSignedBytes( isset( $profile['memory_delta'] ) ? (int) $profile['memory_delta'] : 0 ) . ' memory'
                );
            }
            $this->console->line( 'Log: cron-debug.log' );

            if ( 'real' === $mode ) {
                $this->flashMessage = $this->buildRealCronFlashMessage( $result );
                return 'real_complete';
            }

            $choice = $this->console->promptRunResult();
            if ( '0' === $choice ) {
                return;
            }

            if ( ! $this->repository->exactEventExists( $event ) ) {
                $this->console->warning( 'Cannot re-run: the scheduled event no longer exists.' );
                return;
            }
        }
    }


    private function renderFlashMessage() {
        if ( '' === $this->flashMessage ) {
            return;
        }

        $this->console->line( $this->flashMessage );
        $this->console->line();
        $this->flashMessage = '';
    }

    private function buildRealCronFlashMessage( array $result ) {
        if ( ! empty( $result['success'] ) && ! $this->lifecycleHasProblems( isset( $result['lifecycle'] ) ? $result['lifecycle'] : array() ) ) {
            return '✓ Real cron completed. Event list refreshed.';
        }

        if ( 'timeout' === $result['status'] ) {
            return '✗ Real cron timed out. Event list refreshed; see cron-debug.log.';
        }

        if ( 'exited' === $result['status'] ) {
            return '! Real cron stopped via exit/die. Event list refreshed; see cron-debug.log.';
        }

        return '! Real cron finished with a problem. Event list refreshed; see cron-debug.log.';
    }

    private function executeEvent( array $event, $mode ) {
        $logWriter = new LogWriter( $this->workingDirectory );
        $requestFile = tempnam( sys_get_temp_dir(), 'wp-cron-debug-request-' );
        $resultFile = tempnam( sys_get_temp_dir(), 'wp-cron-debug-result-' );
        if ( false === $requestFile || false === $resultFile ) {
            throw new \RuntimeException( 'Unable to create temporary worker files.' );
        }

        $request = array(
            'mode' => $mode,
            'event' => array(
                'hook' => $event['hook'],
                'timestamp' => $event['timestamp'],
                'args' => $event['args'],
                'schedule' => $event['schedule'],
                'interval' => isset( $event['interval'] ) ? $event['interval'] : 0,
            ),
        );
        if ( false === file_put_contents( $requestFile, json_encode( $request ), LOCK_EX ) ) {
            @unlink( $requestFile );
            @unlink( $resultFile );
            throw new \RuntimeException( 'Unable to write the worker request file.' );
        }
        file_put_contents( $resultFile, '' );

        $execBootstrap = 'if ( ! defined( "DOING_CRON" ) ) { define( "DOING_CRON", true ); }';
        if ( 'profile' === $mode ) {
            $execBootstrap .= ' if ( ! defined( "SAVEQUERIES" ) ) { define( "SAVEQUERIES", true ); }';
        }

        $command = array_merge(
            $this->detectWpCommand(),
            array(
                '--exec=' . $execBootstrap,
                '--path=' . ABSPATH,
                '--url=' . home_url( '/' ),
                'cron-debug',
            )
        );

        $oldRequestEnv = getenv( 'WP_CRON_DEBUG_WORKER_REQUEST' );
        $oldResultEnv = getenv( 'WP_CRON_DEBUG_WORKER_RESULT' );
        putenv( 'WP_CRON_DEBUG_WORKER_REQUEST=' . $requestFile );
        putenv( 'WP_CRON_DEBUG_WORKER_RESULT=' . $resultFile );

        try {
            $result = $this->processRunner->run(
                $command,
                $this->workingDirectory,
                $this->timeout,
                function () {
                    $this->console->tick( 'Running cron event...' );
                }
            );
            $workerMeta = $this->readWorkerMeta( $resultFile );
            $logWriter->write( $event, $result, $workerMeta );
        } finally {
            $this->restoreEnvironment( 'WP_CRON_DEBUG_WORKER_REQUEST', $oldRequestEnv );
            $this->restoreEnvironment( 'WP_CRON_DEBUG_WORKER_RESULT', $oldResultEnv );
            @unlink( $requestFile );
            @unlink( $resultFile );
        }

        $status = ! empty( $result['timed_out'] ) ? 'timeout' : ( isset( $workerMeta['status'] ) ? $workerMeta['status'] : 'failed' );
        $success = 'success' === $status && 0 === (int) $result['exit_code'];
        $duration = isset( $workerMeta['duration'] ) && $workerMeta['duration'] > 0 ? (float) $workerMeta['duration'] : (float) $result['duration'];

        return array(
            'success' => $success,
            'status' => $status,
            'duration' => $duration,
            'mode' => isset( $workerMeta['mode'] ) ? $workerMeta['mode'] : $mode,
            'profile' => isset( $workerMeta['profile'] ) && is_array( $workerMeta['profile'] ) ? $workerMeta['profile'] : array(),
            'lifecycle' => isset( $workerMeta['lifecycle'] ) && is_array( $workerMeta['lifecycle'] ) ? $workerMeta['lifecycle'] : array(),
        );
    }

    private function runWorkerMode( $requestFile, $resultFile ) {
        if ( false === $requestFile || false === $resultFile || '' === $requestFile || '' === $resultFile ) {
            throw new \RuntimeException( 'Incomplete internal worker environment.' );
        }

        $worker = new Worker( (string) $requestFile, (string) $resultFile );
        $worker->run();
    }

    private function restoreEnvironment( $name, $previousValue ) {
        if ( false === $previousValue ) {
            putenv( $name );
            return;
        }

        putenv( $name . '=' . $previousValue );
    }

    private function readWorkerMeta( $resultFile ) {
        if ( ! is_readable( $resultFile ) ) {
            return array( 'status' => 'failed' );
        }
        $raw = file_get_contents( $resultFile );
        $meta = json_decode( (string) $raw, true );
        return is_array( $meta ) ? $meta : array( 'status' => 'failed' );
    }

    private function detectWpCommand() {
        $argv0 = isset( $_SERVER['argv'][0] ) ? (string) $_SERVER['argv'][0] : 'wp';
        if ( '' === $argv0 ) {
            return array( 'wp' );
        }

        if ( is_file( $argv0 ) ) {
            if ( preg_match( '/\.phar$/i', $argv0 ) || ! is_executable( $argv0 ) ) {
                return array( PHP_BINARY, $argv0 );
            }
            return array( $argv0 );
        }

        return array( $argv0 );
    }

    private function parseTimeout( $value ) {
        if ( ! is_numeric( $value ) ) {
            throw new \RuntimeException( 'Timeout must be an integer number of seconds.' );
        }
        $timeout = (int) $value;
        if ( $timeout < 1 || $timeout > self::MAX_TIMEOUT ) {
            throw new \RuntimeException( 'Timeout must be between 1 and ' . self::MAX_TIMEOUT . ' seconds.' );
        }
        return $timeout;
    }

    private function countEvents( array $hooks ) {
        $count = 0;
        foreach ( $hooks as $events ) {
            $count += count( $events );
        }
        return $count;
    }

    private function buildHookSummaries( array $hooks ) {
        $prefixes = array();
        $maxPrefixWidth = 0;

        foreach ( $hooks as $hook => $events ) {
            if ( 1 !== count( $events ) ) {
                continue;
            }

            $event = $events[0];
            $prefix = $event['source'] . ' · ' . $this->scheduleLabel( $event );
            $prefixes[ $hook ] = $prefix;
            $maxPrefixWidth = max( $maxPrefixWidth, strlen( $prefix ) );
        }

        $summaries = array();
        foreach ( $hooks as $hook => $events ) {
            $event = $events[0];
            $source = $event['source'];

            if ( count( $events ) > 1 ) {
                $summaries[ $hook ] = $source . ' · ' . count( $events ) . ' instances';
                continue;
            }

            $summaries[ $hook ] = str_pad( $prefixes[ $hook ], $maxPrefixWidth, ' ' )
                . ' · ' . $this->relativeTime( $event['timestamp'] );
        }

        return $summaries;
    }

    private function scheduleLabel( array $event ) {
        return $event['schedule'] ? (string) $event['schedule'] : 'single';
    }

    private function relativeTime( $timestamp ) {
        $timestamp = (int) $timestamp;
        $now = time();
        if ( $timestamp <= $now ) {
            return 'overdue ' . human_time_diff( $timestamp, $now );
        }
        return 'in ' . human_time_diff( $now, $timestamp );
    }

    private function shortJson( $value ) {
        $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) {
            return '[unserializable]';
        }
        return strlen( $json ) > 70 ? substr( $json, 0, 67 ) . '...' : $json;
    }

    private function fullJson( $value ) {
        $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return false === $json ? '[unserializable]' : $json;
    }

    private function lifecycleHasProblems( array $lifecycle ) {
        foreach ( array( 'rescheduled', 'unscheduled' ) as $key ) {
            if ( ! isset( $lifecycle[ $key ] ) || 'not_applicable' === $lifecycle[ $key ] ) {
                continue;
            }
            if ( 'success' !== $lifecycle[ $key ] ) {
                return true;
            }
        }
        return false;
    }

    private function hookDiagnosticStatus( array $events ) {
        $diagnostics = $this->diagnostics instanceof EventDiagnostics ? $this->diagnostics : new EventDiagnostics();
        $status = EventDiagnostics::OK;

        foreach ( $events as $event ) {
            foreach ( $diagnostics->analyze( $event ) as $diagnostic ) {
                if ( EventDiagnostics::ERROR === $diagnostic['level'] ) {
                    return EventDiagnostics::ERROR;
                }

                if ( EventDiagnostics::WARNING === $diagnostic['level'] ) {
                    $status = EventDiagnostics::WARNING;
                }
            }
        }

        return $status;
    }

    private function formatStatusColumn( $status ) {
        $level = 'ok';
        $label = '✓ OK';

        if ( EventDiagnostics::ERROR === $status ) {
            $level = 'error';
            $label = '✗ ERROR';
        } elseif ( EventDiagnostics::WARNING === $status ) {
            $level = 'warning';
            $label = '! WARN';
        }

        return $this->console->statusText( $level, str_pad( $label, 12, ' ' ) );
    }

    private function executionModeLabel( $mode ) {
        if ( 'profile' === $mode ) {
            return 'Profile run / schedule preserved';
        }
        if ( 'real' === $mode ) {
            return 'Real cron lifecycle / schedule updated';
        }
        return 'Debug run / schedule preserved';
    }

    private function formatSignedBytes( $bytes ) {
        $bytes = (int) $bytes;
        if ( 0 === $bytes ) {
            return '0 B';
        }

        $units = array( 'B', 'KB', 'MB', 'GB' );
        $value = (float) abs( $bytes );
        $index = 0;
        while ( $value >= 1024 && $index < count( $units ) - 1 ) {
            $value /= 1024;
            $index++;
        }

        return ( $bytes > 0 ? '+' : '-' ) . number_format( $value, 0 === $index ? 0 : 1 ) . ' ' . $units[ $index ];
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
}
