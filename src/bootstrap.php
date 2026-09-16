<?php

if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

WP_CLI::add_command( 'cron-debug', 'WpCronDebug\\Command' );
