<?php

namespace WpCronDebug;

final class CronSnapshot {
    /**
     * Read the current cron option directly from the database.
     *
     * The interactive command is a long-lived PHP process while isolated
     * workers can update the cron option in separate processes. Reading via
     * get_option() / _get_cron_array() can therefore return a stale in-memory
     * alloptions snapshot after a real-cron run.
     *
     * @return array
     */
    public static function readFresh() {
        global $wpdb;

        if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'get_var' ) ) {
            $table = (string) $wpdb->options;
            if ( '' === $table || ! preg_match( '/^[A-Za-z0-9_$]+$/', $table ) ) {
                throw new \RuntimeException( 'Unable to read cron events: invalid WordPress options table name.' );
            }

            $query = "SELECT option_value FROM `{$table}` WHERE option_name = 'cron' LIMIT 1";
            $raw = $wpdb->get_var( $query );
            if ( null === $raw || false === $raw ) {
                return array();
            }

            $crons = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : @unserialize( $raw );
            if ( ! is_array( $crons ) ) {
                return array();
            }

            if ( ! isset( $crons['version'] ) && function_exists( '_upgrade_cron_array' ) ) {
                $crons = _upgrade_cron_array( $crons );
            }

            unset( $crons['version'] );
            return is_array( $crons ) ? $crons : array();
        }

        if ( function_exists( '_get_cron_array' ) ) {
            $crons = _get_cron_array();
            return is_array( $crons ) ? $crons : array();
        }

        return array();
    }
}
