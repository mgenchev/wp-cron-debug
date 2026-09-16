<?php

namespace WpCronDebug;

final class CronSnapshot {
    /**
     * Read the current cron array without reusing the long-lived parent
     * process option cache.
     *
     * Prefer WordPress' own cron API so pre_option_cron and replacement cron
     * storage integrations remain effective. Newer WordPress versions expose
     * a fresh-read parameter on _get_cron_array(); older versions require
     * invalidating the option caches before asking core for the array again.
     *
     * @return array
     */
    public static function readFresh() {
        if ( function_exists( '_get_cron_array' ) ) {
            try {
                $reflection = new \ReflectionFunction( '_get_cron_array' );
                if ( $reflection->getNumberOfParameters() >= 1 ) {
                    $crons = _get_cron_array( true );
                    return is_array( $crons ) ? $crons : array();
                }
            } catch ( \ReflectionException $e ) {
                // Fall through to the cache-invalidation path.
            }

            self::invalidateOptionCache();
            $crons = _get_cron_array();
            return is_array( $crons ) ? $crons : array();
        }

        self::invalidateOptionCache();
        if ( function_exists( 'get_option' ) ) {
            $crons = get_option( 'cron', array() );
            if ( ! is_array( $crons ) ) {
                return array();
            }

            if ( ! isset( $crons['version'] ) && function_exists( '_upgrade_cron_array' ) ) {
                $crons = _upgrade_cron_array( $crons );
            }

            unset( $crons['version'] );
            return $crons;
        }

        return array();
    }

    private static function invalidateOptionCache() {
        if ( ! function_exists( 'wp_cache_delete' ) ) {
            return;
        }

        wp_cache_delete( 'cron', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }
}
