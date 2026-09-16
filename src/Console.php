<?php

namespace WpCronDebug;

final class Console {
    private const SPINNER_FRAMES = array( '⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏' );

    private $frameIndex = 0;
    private $lastTick = 0.0;
    private $interactive;

    public function __construct( $interactive = null ) {
        if ( null !== $interactive ) {
            $this->interactive = (bool) $interactive;
            return;
        }

        if ( function_exists( 'stream_isatty' ) ) {
            $this->interactive = @stream_isatty( STDOUT );
            return;
        }

        $this->interactive = function_exists( 'posix_isatty' ) ? @posix_isatty( STDOUT ) : false;
    }

    public function line( $message = '' ) {
        \WP_CLI::line( $message );
    }

    public function warning( $message ) {
        \WP_CLI::warning( $message );
    }

    public function errorLine( $message ) {
        $this->line( $this->statusText( 'error', '✗' ) . ' ' . $message );
    }

    public function success( $message ) {
        $this->clearSpinner();
        $this->line( $this->statusText( 'ok', '✓' ) . ' ' . $message );
    }

    public function tick( $message, $force = false ) {
        $now = microtime( true );

        if ( ! $force && ( $now - $this->lastTick ) < 0.08 ) {
            return;
        }

        $frame = self::SPINNER_FRAMES[ $this->frameIndex % count( self::SPINNER_FRAMES ) ];
        $this->frameIndex++;
        $this->lastTick = $now;

        if ( $this->interactive ) {
            fwrite( STDOUT, "\r\033[2K" . $frame . ' ' . $message );
            return;
        }

        $this->line( $frame . ' ' . $message );
    }

    public function clearSpinner() {
        if ( $this->interactive ) {
            fwrite( STDOUT, "\r\033[2K" );
        }
    }

    public function clearScreen() {
        if ( ! $this->supportsScreenControl() ) {
            return;
        }

        fwrite( STDOUT, "\033[2J\033[H" );
    }

    private function supportsScreenControl() {
        if ( ! $this->interactive ) {
            return false;
        }

        $term = getenv( 'TERM' );
        if ( false !== $term && 'dumb' === strtolower( trim( $term ) ) ) {
            return false;
        }

        if ( '\\' !== DIRECTORY_SEPARATOR ) {
            return true;
        }

        if ( function_exists( 'sapi_windows_vt100_support' ) ) {
            return @sapi_windows_vt100_support( STDOUT, true );
        }

        return false;
    }


    public function statusText( $level, $text ) {
        $tokens = array(
            'ok' => '%G',
            'warning' => '%Y',
            'error' => '%R',
        );

        if ( ! isset( $tokens[ $level ] ) || ! method_exists( '\\WP_CLI', 'colorize' ) ) {
            return $text;
        }

        return \WP_CLI::colorize( $tokens[ $level ] . $text . '%n' );
    }

    public function section( $title ) {
        $this->line();
        $this->line( $title );
        $this->line( str_repeat( '-', 68 ) );
    }

    public function renderMenu( $title, array $choices, array $columns = array(), $withBack = false ) {
        $this->line();
        $this->line( $title );
        $this->line();

        $firstColumnWidth = 0;
        foreach ( $choices as $key => $label ) {
            $firstColumnWidth = max( $firstColumnWidth, strlen( '[' . $key . '] ' . $label ) );
        }

        foreach ( $choices as $key => $label ) {
            $left = '[' . $key . '] ' . $label;
            $right = isset( $columns[ $key ] ) ? trim( (string) $columns[ $key ] ) : '';

            if ( '' === $right ) {
                $this->line( $left );
                continue;
            }

            $this->line( str_pad( $left, $firstColumnWidth + 4, ' ' ) . $right );
        }

        if ( $withBack ) {
            $this->line( '[0] Back' );
        }
        $this->line( '[x] Exit' );
        $this->line();
    }

    public function promptMenu( $title, array $choices, array $columns = array(), $withBack = false ) {
        $this->renderMenu( $title, $choices, $columns, $withBack );

        return $this->promptRenderedMenu( $choices, $withBack );
    }

    public function promptRenderedMenu( array $choices, $withBack = false ) {
        return $this->promptKey( 'Select: ', array_keys( $choices ), $withBack );
    }

    public function promptKey( $prompt, array $validKeys, $withBack = false ) {
        $valid = array_fill_keys( array_map( 'strval', $validKeys ), true );

        while ( true ) {
            $value = strtolower( trim( $this->readLine( $prompt ) ) );

            if ( 'x' === $value ) {
                throw new ExitRequested();
            }

            if ( $withBack && '0' === $value ) {
                return '0';
            }

            if ( isset( $valid[ $value ] ) ) {
                return (string) $value;
            }

            $this->warning( 'Invalid selection.' );
        }
    }

    public function promptRunResult() {
        $choices = array(
            '1' => 'Run again',
        );

        $this->line();
        $this->line( '[1] Run again' );
        $this->line( '[0] Back' );
        $this->line( '[x] Exit' );
        $this->line();

        return $this->promptRenderedMenu( $choices, true );
    }

    public function promptDetailsResult() {
        $choices = array(
            '1' => 'Debug run',
            '2' => 'Profile run',
            '3' => 'Run as real cron',
        );

        $this->line();
        $this->line( '[1] Debug run            Schedule preserved' );
        $this->line( '[2] Profile run          Schedule preserved + DB profile' );
        $this->line( '[3] Run as real cron     Reschedule/unschedule like WP-Cron' );
        $this->line( '[0] Back' );
        $this->line( '[x] Exit' );
        $this->line();

        return $this->promptRenderedMenu( $choices, true );
    }

    private function readLine( $prompt ) {
        if ( function_exists( 'readline' ) ) {
            $value = readline( $prompt );
            return false === $value ? '' : $value;
        }

        fwrite( STDOUT, $prompt );
        $value = fgets( STDIN );
        return false === $value ? '' : $value;
    }
}
