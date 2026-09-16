<?php

namespace WpCronDebug;

final class EventDiagnostics {
    private const OVERDUE_WARNING_SECONDS = 300;

    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    public function analyze( array $event ) {
        $issues = array();
        $callbacks = isset( $event['callbacks'] ) && is_array( $event['callbacks'] ) ? $event['callbacks'] : array();

        if ( empty( $callbacks ) ) {
            $issues[] = $this->issue( self::ERROR, 'No registered callback for this hook.' );
        } else {
            $uncallable = 0;
            foreach ( $callbacks as $callback ) {
                if ( empty( $callback['callable'] ) ) {
                    $uncallable++;
                }
            }

            if ( $uncallable > 0 ) {
                $issues[] = $this->issue(
                    self::ERROR,
                    sprintf( '%d registered callback%s not callable.', $uncallable, 1 === $uncallable ? ' is' : 's are' )
                );
            }
        }

        $timestamp = isset( $event['timestamp'] ) ? (int) $event['timestamp'] : 0;
        if ( $timestamp > 0 && ( time() - $timestamp ) >= self::OVERDUE_WARNING_SECONDS ) {
            $issues[] = $this->issue( self::WARNING, 'Event is overdue by ' . human_time_diff( $timestamp, time() ) . '.' );
        }

        $schedule = isset( $event['schedule'] ) ? $event['schedule'] : false;
        if ( false !== $schedule && '' !== (string) $schedule ) {
            $schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();
            if ( is_array( $schedules ) && ! isset( $schedules[ $schedule ] ) ) {
                $issues[] = $this->issue( self::WARNING, 'Schedule "' . $schedule . '" is not currently registered.' );
            }

            $interval = isset( $event['interval'] ) ? (int) $event['interval'] : 0;
            if ( $interval <= 0 ) {
                $issues[] = $this->issue( self::WARNING, 'Recurring event has no valid interval.' );
            } elseif ( isset( $schedules[ $schedule ]['interval'] ) && (int) $schedules[ $schedule ]['interval'] !== $interval ) {
                $issues[] = $this->issue(
                    self::WARNING,
                    sprintf(
                        'Stored interval (%d s) differs from the current "%s" schedule (%d s).',
                        $interval,
                        $schedule,
                        (int) $schedules[ $schedule ]['interval']
                    )
                );
            }
        }

        if ( isset( $event['signature'] ) && isset( $event['args'] ) && is_array( $event['args'] ) ) {
            $expected = md5( serialize( $event['args'] ) );
            if ( ! hash_equals( $expected, (string) $event['signature'] ) ) {
                $issues[] = $this->issue( self::ERROR, 'Stored cron signature does not match the event arguments.' );
            }
        }

        if ( empty( $issues ) ) {
            $issues[] = $this->issue( self::OK, 'No obvious issues detected.' );
        }

        return $issues;
    }

    private function issue( $level, $message ) {
        return array(
            'level' => $level,
            'message' => $message,
        );
    }
}
