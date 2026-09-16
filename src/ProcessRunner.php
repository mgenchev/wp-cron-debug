<?php

namespace WpCronDebug;

final class ProcessRunner {
    public const MAX_CAPTURE_BYTES = 10485760;

    public function run( array $command, $cwd, $timeout, callable $tick = null ) {
        if ( ! function_exists( 'proc_open' ) ) {
            throw new \RuntimeException( 'proc_open() is disabled; isolated cron execution is unavailable.' );
        }

        $descriptorSpec = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );
        $pipes = array();
        $process = proc_open( $command, $descriptorSpec, $pipes, $cwd, null );
        if ( ! is_resource( $process ) ) {
            throw new \RuntimeException( 'Unable to start the isolated WP-CLI worker process.' );
        }

        fclose( $pipes[0] );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );

        $stdout = '';
        $stderr = '';
        $captured = 0;
        $truncated = false;
        $timedOut = false;
        $started = microtime( true );
        $lastStatus = null;

        while ( true ) {
            $status = proc_get_status( $process );
            $lastStatus = $status;

            $this->drainPipe( $pipes[1], $stdout, $captured, $truncated );
            $this->drainPipe( $pipes[2], $stderr, $captured, $truncated );

            if ( null !== $tick ) {
                $tick();
            }

            if ( ! $status['running'] ) {
                break;
            }

            if ( ( microtime( true ) - $started ) >= $timeout ) {
                $timedOut = true;
                proc_terminate( $process );
                usleep( 200000 );
                $status = proc_get_status( $process );
                if ( $status['running'] ) {
                    proc_terminate( $process, 9 );
                }
                break;
            }

            usleep( 50000 );
        }

        $this->drainPipe( $pipes[1], $stdout, $captured, $truncated );
        $this->drainPipe( $pipes[2], $stderr, $captured, $truncated );
        fclose( $pipes[1] );
        fclose( $pipes[2] );

        $exitCode = proc_close( $process );
        if ( -1 === $exitCode && is_array( $lastStatus ) && isset( $lastStatus['exitcode'] ) && $lastStatus['exitcode'] >= 0 ) {
            $exitCode = (int) $lastStatus['exitcode'];
        }

        return array(
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'truncated' => $truncated,
            'duration' => microtime( true ) - $started,
        );
    }

    private function drainPipe( $pipe, &$buffer, &$captured, &$truncated ) {
        while ( ! feof( $pipe ) ) {
            $chunk = fread( $pipe, 8192 );
            if ( false === $chunk || '' === $chunk ) {
                break;
            }

            $remaining = self::MAX_CAPTURE_BYTES - $captured;
            if ( $remaining <= 0 ) {
                $truncated = true;
                continue;
            }

            if ( strlen( $chunk ) > $remaining ) {
                $buffer .= substr( $chunk, 0, $remaining );
                $captured += $remaining;
                $truncated = true;
                continue;
            }

            $buffer .= $chunk;
            $captured += strlen( $chunk );
        }
    }
}
