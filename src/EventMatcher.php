<?php

namespace WpCronDebug;

final class EventMatcher {
    public static function exists( $hook, array $args, $timestamp ) {
        $crons = CronSnapshot::readFresh();
        if ( ! is_array( $crons ) ) {
            return false;
        }

        $timestamp = (int) $timestamp;
        $signature = md5( serialize( $args ) );

        return isset( $crons[ $timestamp ][ $hook ][ $signature ] );
    }
}
