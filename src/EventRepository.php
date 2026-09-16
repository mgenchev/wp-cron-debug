<?php

namespace WpCronDebug;

final class EventRepository {
    private $classifier;

    public function __construct( SourceClassifier $classifier ) {
        $this->classifier = $classifier;
    }

    public function allGroupedByCategory() {
        $crons = CronSnapshot::readFresh();
        $groups = array(
            SourceClassifier::CORE => array(),
            SourceClassifier::PLUGINS => array(),
            SourceClassifier::THEME => array(),
            SourceClassifier::OTHER => array(),
        );

        if ( ! is_array( $crons ) ) {
            return $groups;
        }

        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $instances ) {
                $callbacks = $this->callbacksForHook( $hook );
                $category = $this->classifier->classifyCallbacks( $callbacks );
                $source = $this->classifier->sourceLabel( $callbacks, $category );

                foreach ( $instances as $signature => $data ) {
                    $event = array(
                        'hook' => (string) $hook,
                        'timestamp' => (int) $timestamp,
                        'signature' => (string) $signature,
                        'args' => isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array(),
                        'schedule' => isset( $data['schedule'] ) ? $data['schedule'] : false,
                        'interval' => isset( $data['interval'] ) ? (int) $data['interval'] : 0,
                        'callbacks' => $callbacks,
                        'category' => $category,
                        'source' => $source,
                    );

                    if ( ! isset( $groups[ $category ][ $hook ] ) ) {
                        $groups[ $category ][ $hook ] = array();
                    }
                    $groups[ $category ][ $hook ][] = $event;
                }
            }
        }

        foreach ( $groups as &$hooks ) {
            ksort( $hooks, SORT_NATURAL | SORT_FLAG_CASE );
            foreach ( $hooks as &$events ) {
                usort( $events, static function ( $a, $b ) {
                    if ( $a['timestamp'] === $b['timestamp'] ) {
                        return strcmp( $a['signature'], $b['signature'] );
                    }
                    return $a['timestamp'] < $b['timestamp'] ? -1 : 1;
                } );
            }
            unset( $events );
        }
        unset( $hooks );

        return $groups;
    }

    public function eventsForHook( $hook ) {
        $groups = $this->allGroupedByCategory();
        $events = array();

        foreach ( $groups as $hooks ) {
            if ( isset( $hooks[ $hook ] ) ) {
                $events = array_merge( $events, $hooks[ $hook ] );
            }
        }

        usort( $events, static function ( $a, $b ) {
            if ( $a['timestamp'] === $b['timestamp'] ) {
                return strcmp( $a['signature'], $b['signature'] );
            }
            return $a['timestamp'] < $b['timestamp'] ? -1 : 1;
        } );

        return $events;
    }

    public function exactEventExists( array $event ) {
        return EventMatcher::exists( $event['hook'], $event['args'], (int) $event['timestamp'] );
    }

    public function callbacksForHook( $hook ) {
        global $wp_filter;

        if ( ! isset( $wp_filter[ $hook ] ) ) {
            return array();
        }

        $callbacks = array();
        $hookObject = $wp_filter[ $hook ];
        $priorities = isset( $hookObject->callbacks ) && is_array( $hookObject->callbacks ) ? $hookObject->callbacks : array();

        foreach ( $priorities as $priority => $items ) {
            foreach ( $items as $item ) {
                if ( ! isset( $item['function'] ) ) {
                    continue;
                }
                $callbacks[] = $this->describeCallback( $item['function'], (int) $priority );
            }
        }

        return $callbacks;
    }

    private function describeCallback( $callback, $priority ) {
        $name = 'Unknown callback';
        $file = '';
        $line = 0;

        try {
            if ( is_string( $callback ) ) {
                $name = $callback;
                if ( function_exists( $callback ) ) {
                    $reflection = new \ReflectionFunction( $callback );
                    $file = (string) $reflection->getFileName();
                    $line = (int) $reflection->getStartLine();
                }
            } elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
                $class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
                $name = $class . '::' . (string) $callback[1];
                $reflection = new \ReflectionMethod( $callback[0], $callback[1] );
                $file = (string) $reflection->getFileName();
                $line = (int) $reflection->getStartLine();
            } elseif ( $callback instanceof \Closure ) {
                $name = 'Closure';
                $reflection = new \ReflectionFunction( $callback );
                $file = (string) $reflection->getFileName();
                $line = (int) $reflection->getStartLine();
            } elseif ( is_object( $callback ) && is_callable( $callback ) ) {
                $name = get_class( $callback ) . '::__invoke';
                $reflection = new \ReflectionMethod( $callback, '__invoke' );
                $file = (string) $reflection->getFileName();
                $line = (int) $reflection->getStartLine();
            }
        } catch ( \ReflectionException $e ) {
            $file = '';
            $line = 0;
        }

        return array(
            'name' => $name,
            'file' => $file,
            'line' => $line,
            'priority' => $priority,
            'callable' => is_callable( $callback ),
        );
    }
}
