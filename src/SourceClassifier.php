<?php

namespace WpCronDebug;

final class SourceClassifier {
    public const CORE = 'core';
    public const PLUGINS = 'plugins';
    public const THEME = 'theme';
    public const OTHER = 'other';

    private $coreRoots;
    private $pluginRoots;
    private $themeRoots;

    public function __construct( array $coreRoots, array $pluginRoots, array $themeRoots ) {
        $this->coreRoots = $this->normalizeRoots( $coreRoots );
        $this->pluginRoots = $this->normalizeRoots( $pluginRoots );
        $this->themeRoots = $this->normalizeRoots( $themeRoots );
    }

    public static function fromWordPress() {
        $coreRoots = array(
            ABSPATH . WPINC,
            ABSPATH . 'wp-admin',
        );
        $pluginRoots = array();
        $themeRoots = array();

        if ( defined( 'WP_PLUGIN_DIR' ) ) {
            $pluginRoots[] = WP_PLUGIN_DIR;
        }
        if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
            $pluginRoots[] = WPMU_PLUGIN_DIR;
        }
        if ( function_exists( 'get_theme_root' ) ) {
            $themeRoots[] = get_theme_root();
        }

        return new self( $coreRoots, $pluginRoots, $themeRoots );
    }

    public function classifyFile( $file ) {
        $file = $this->normalizePath( $file );
        if ( '' === $file ) {
            return self::OTHER;
        }

        if ( $this->isWithinAnyRoot( $file, $this->coreRoots ) ) {
            return self::CORE;
        }
        if ( $this->isWithinAnyRoot( $file, $this->pluginRoots ) ) {
            return self::PLUGINS;
        }
        if ( $this->isWithinAnyRoot( $file, $this->themeRoots ) ) {
            return self::THEME;
        }

        return self::OTHER;
    }

    public function classifyCallbacks( array $callbacks ) {
        if ( empty( $callbacks ) ) {
            return self::OTHER;
        }

        $categories = array();
        foreach ( $callbacks as $callback ) {
            $categories[ $this->classifyFile( isset( $callback['file'] ) ? $callback['file'] : '' ) ] = true;
        }

        if ( 1 === count( $categories ) ) {
            return (string) key( $categories );
        }

        return self::OTHER;
    }

    public function sourceLabel( array $callbacks, $category ) {
        $labels = array();
        foreach ( $callbacks as $callback ) {
            $file = isset( $callback['file'] ) ? $this->normalizePath( $callback['file'] ) : '';
            if ( '' === $file ) {
                continue;
            }

            $label = $this->labelForFile( $file, $category );
            if ( '' !== $label ) {
                $labels[ $label ] = true;
            }
        }

        if ( empty( $labels ) ) {
            return self::OTHER === $category ? 'Unknown source' : ucfirst( $category );
        }

        $names = array_keys( $labels );
        sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
        return implode( ', ', $names );
    }

    private function labelForFile( $file, $category ) {
        if ( self::CORE === $category ) {
            return 'WordPress Core';
        }

        $roots = self::PLUGINS === $category ? $this->pluginRoots : ( self::THEME === $category ? $this->themeRoots : array() );
        foreach ( $roots as $root ) {
            if ( $this->isWithinRoot( $file, $root ) ) {
                $relative = ltrim( substr( $file, strlen( $root ) ), '/' );
                if ( '' === $relative ) {
                    return basename( $root );
                }
                $parts = explode( '/', $relative );
                return (string) $parts[0];
            }
        }

        return basename( $file );
    }

    private function normalizeRoots( array $roots ) {
        $normalized = array();
        foreach ( $roots as $root ) {
            $root = $this->normalizePath( $root );
            if ( '' !== $root ) {
                $normalized[] = rtrim( $root, '/' );
            }
        }
        return array_values( array_unique( $normalized ) );
    }

    private function normalizePath( $path ) {
        if ( ! is_string( $path ) || '' === $path ) {
            return '';
        }

        $real = realpath( $path );
        $path = false !== $real ? $real : $path;
        return str_replace( '\\', '/', rtrim( $path, '/\\' ) );
    }

    private function isWithinAnyRoot( $file, array $roots ) {
        foreach ( $roots as $root ) {
            if ( $this->isWithinRoot( $file, $root ) ) {
                return true;
            }
        }
        return false;
    }

    private function isWithinRoot( $file, $root ) {
        return $file === $root || 0 === strpos( $file, $root . '/' );
    }
}
