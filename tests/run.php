<?php

$root = dirname( __DIR__ );

final class WP_CLI {
    public static $lines = array();
    public static $warnings = array();
    public static $commands = array();

    public static function line( $message = '' ) {
        self::$lines[] = $message;
    }

    public static function warning( $message ) {
        self::$warnings[] = $message;
    }

    public static function colorize( $message ) {
        return preg_replace( '/%[A-Za-z0-9_]/', '', $message );
    }

    public static function error( $message ) {
        throw new RuntimeException( $message );
    }

    public static function add_command( $name, $callable ) {
        self::$commands[ $name ] = $callable;
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/srv/www/' );
}
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
}
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
    define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
}

$GLOBALS['test_crons'] = array();

$GLOBALS['test_db_crons_override'] = null;

final class TestWpdb {
    public $options = 'wp_options';

    public function get_var( $query ) {
        $crons = is_array( $GLOBALS['test_db_crons_override'] ) ? $GLOBALS['test_db_crons_override'] : $GLOBALS['test_crons'];
        return serialize( $crons );
    }
}

$GLOBALS['wpdb'] = new TestWpdb();
$GLOBALS['test_scheduled_event_exists'] = true;
$GLOBALS['test_action_callback'] = null;
$GLOBALS['test_lifecycle_calls'] = array();
$GLOBALS['wp_filter'] = array();

function wp_cache_delete( $key, $group = '' ) {
    return true;
}

function _get_cron_array() {
    return $GLOBALS['test_crons'];
}

function wp_get_scheduled_event( $hook, $args = array(), $timestamp = null ) {
    if ( ! $GLOBALS['test_scheduled_event_exists'] ) {
        return false;
    }

    $signature = md5( serialize( $args ) );
    if ( isset( $GLOBALS['test_crons'][ (int) $timestamp ][ $hook ][ $signature ] ) ) {
        $data = $GLOBALS['test_crons'][ (int) $timestamp ][ $hook ][ $signature ];
        return (object) array(
            'hook' => $hook,
            'timestamp' => (int) $timestamp,
            'args' => $args,
            'schedule' => isset( $data['schedule'] ) ? $data['schedule'] : false,
            'interval' => isset( $data['interval'] ) ? (int) $data['interval'] : 0,
        );
    }

    return false;
}

function wp_get_schedules() {
    return array(
        'hourly' => array( 'interval' => 3600, 'display' => 'Once Hourly' ),
        'twicedaily' => array( 'interval' => 43200, 'display' => 'Twice Daily' ),
        'daily' => array( 'interval' => 86400, 'display' => 'Once Daily' ),
        'weekly' => array( 'interval' => 604800, 'display' => 'Once Weekly' ),
    );
}

function wp_reschedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
    $GLOBALS['test_lifecycle_calls'][] = array( 'reschedule', (int) $timestamp, (string) $recurrence, (string) $hook, $args );
    return true;
}

function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
    $GLOBALS['test_lifecycle_calls'][] = array( 'unschedule', (int) $timestamp, (string) $hook, $args );
    return true;
}

function is_wp_error( $value ) {
    return false;
}

function do_action_ref_array( $hook, $args ) {
    if ( is_callable( $GLOBALS['test_action_callback'] ) ) {
        call_user_func_array( $GLOBALS['test_action_callback'], $args );
    }
}

function human_time_diff( $from, $to = 0 ) {
    return abs( (int) $to - (int) $from ) . ' seconds';
}

function home_url( $path = '' ) {
    return 'https://example.test' . $path;
}

foreach ( glob( $root . '/src/*.php' ) as $file ) {
    if ( basename( $file ) === 'bootstrap.php' ) {
        continue;
    }
    require_once $file;
}

use WpCronDebug\Console;
use WpCronDebug\EventDiagnostics;
use WpCronDebug\EventMatcher;
use WpCronDebug\EventRepository;
use WpCronDebug\LogWriter;
use WpCronDebug\ProcessRunner;
use WpCronDebug\SourceClassifier;
use WpCronDebug\Worker;

$tests = 0;
$failures = 0;

function assert_true( $condition, $message ) {
    global $tests, $failures;
    $tests++;
    if ( ! $condition ) {
        $failures++;
        fwrite( STDERR, "FAIL: {$message}\n" );
    }
}

function assert_same( $expected, $actual, $message ) {
    assert_true( $expected === $actual, $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

require $root . '/src/bootstrap.php';
assert_same( 'WpCronDebug\\Command', WP_CLI::$commands['cron-debug'], 'Bootstrap registers wp cron-debug directly.' );

// Source classification positive and negative coverage.
$classifier = new SourceClassifier(
    array( '/srv/www/wp-includes', '/srv/www/wp-admin' ),
    array( '/srv/www/wp-content/plugins', '/srv/www/wp-content/mu-plugins' ),
    array( '/srv/www/wp-content/themes' )
);
assert_same( SourceClassifier::CORE, $classifier->classifyFile( '/srv/www/wp-includes/cron.php' ), 'Core file is classified as core.' );
assert_same( SourceClassifier::PLUGINS, $classifier->classifyFile( '/srv/www/wp-content/plugins/shop/plugin.php' ), 'Plugin file is classified as plugin.' );
assert_same( SourceClassifier::PLUGINS, $classifier->classifyFile( '/srv/www/wp-content/mu-plugins/tools.php' ), 'MU plugin file is classified as plugin.' );
assert_same( SourceClassifier::THEME, $classifier->classifyFile( '/srv/www/wp-content/themes/site/functions.php' ), 'Theme file is classified as theme.' );
assert_same( SourceClassifier::OTHER, $classifier->classifyFile( '/opt/shared/cron.php' ), 'Unknown path is classified as other.' );
assert_same( SourceClassifier::OTHER, $classifier->classifyCallbacks( array(
    array( 'file' => '/srv/www/wp-includes/cron.php' ),
    array( 'file' => '/srv/www/wp-content/plugins/shop/plugin.php' ),
) ), 'Mixed callback origins are conservatively classified as Other.' );

assert_same( SourceClassifier::OTHER, $classifier->classifyFile( '/srv/www/wp-content/plugins-evil/plugin.php' ), 'Path-prefix lookalike is not misclassified as a plugin.' );
assert_same( SourceClassifier::PLUGINS, $classifier->classifyCallbacks( array(
    array( 'file' => '/srv/www/wp-content/plugins/a/plugin.php' ),
    array( 'file' => '/srv/www/wp-content/plugins/b/plugin.php' ),
) ), 'Multiple plugin callbacks remain in Plugins.' );

// Event grouping retains exact event instances.
$GLOBALS['test_crons'] = array(
    100 => array(
        'sample_hook' => array(
            md5( serialize( array( 1 ) ) ) => array( 'schedule' => 'hourly', 'args' => array( 1 ), 'interval' => 3600 ),
            md5( serialize( array( 2 ) ) ) => array( 'schedule' => 'daily', 'args' => array( 2 ), 'interval' => 86400 ),
        ),
    ),
);
$repository = new EventRepository( $classifier );
$groups = $repository->allGroupedByCategory();
assert_true( isset( $groups[ SourceClassifier::OTHER ]['sample_hook'] ), 'Hook without registered callback is kept in Other.' );
assert_same( 2, count( $groups[ SourceClassifier::OTHER ]['sample_hook'] ), 'Multiple scheduled instances are preserved.' );
$instanceArgs = array_map( static function ( $event ) { return $event['args'][0]; }, $groups[ SourceClassifier::OTHER ]['sample_hook'] );
sort( $instanceArgs );
assert_same( array( 1, 2 ), $instanceArgs, 'Both scheduled-instance argument sets are preserved.' );
assert_true( EventMatcher::exists( 'sample_hook', array( 1 ), 100 ), 'Exact event matcher accepts matching hook, args, and timestamp.' );
assert_true( ! EventMatcher::exists( 'sample_hook', array( 9 ), 100 ), 'Exact event matcher rejects different arguments.' );
assert_true( ! EventMatcher::exists( 'sample_hook', array( 1 ), 101 ), 'Exact event matcher rejects different timestamp.' );


// Regression: the interactive parent process must not reuse a stale in-memory cron option after a child worker reschedules an event.
$oldSignature = md5( serialize( array( 1 ) ) );
$GLOBALS['test_crons'] = array(
    100 => array(
        'sample_hook' => array(
            $oldSignature => array( 'schedule' => 'hourly', 'args' => array( 1 ), 'interval' => 3600 ),
        ),
    ),
);
$GLOBALS['test_db_crons_override'] = array(
    3700 => array(
        'sample_hook' => array(
            $oldSignature => array( 'schedule' => 'hourly', 'args' => array( 1 ), 'interval' => 3600 ),
        ),
    ),
);
$freshGroups = $repository->allGroupedByCategory();
$freshEvents = $freshGroups[ SourceClassifier::OTHER ]['sample_hook'];
assert_same( 3700, $freshEvents[0]['timestamp'], 'Event list refresh reads the current cron snapshot directly from the database after a real-cron reschedule.' );
assert_true( ! EventMatcher::exists( 'sample_hook', array( 1 ), 100 ), 'Exact matcher rejects the stale pre-reschedule event even when the parent process still has an old cron snapshot.' );
assert_true( EventMatcher::exists( 'sample_hook', array( 1 ), 3700 ), 'Exact matcher accepts the freshly rescheduled event from the database snapshot.' );
$GLOBALS['test_db_crons_override'] = null;


// Log preserves developer output exactly, including HTML wrappers and long var_dump strings.
$tmpRaw = sys_get_temp_dir() . '/wp-cron-debug-raw-test-' . uniqid( '', true );
mkdir( $tmpRaw );
$rawWriter = new LogWriter( $tmpRaw );
$longValue = str_repeat( 'A', 3000 );
$rawEvent = array(
    'hook' => 'raw_hook',
    'category' => SourceClassifier::THEME,
    'source' => 'sample-theme',
    'callbacks' => array( array( 'name' => 'raw_callback' ) ),
    'schedule' => 'hourly',
    'args' => array(),
);
$rawResult = array( 'stdout' => '<pre>string(3000) "' . $longValue . '"</pre>', 'stderr' => '', 'timed_out' => false, 'truncated' => false, 'exit_code' => 0, 'duration' => 0.1 );
$rawMeta = array( 'status' => 'exited', 'fatal' => false, 'started_at' => '2026-09-16 06:35:01 UTC', 'duration' => 0.1, 'peak_memory' => 1024 );
$rawWriter->write( $rawEvent, $rawResult, $rawMeta );
$rawLog = file_get_contents( $rawWriter->getPath() );
assert_true( false !== strpos( $rawLog, 'Status:      EXITED' ), 'A callback that calls exit/die is reported as EXITED instead of generic TERMINATED.' );
assert_true( false !== strpos( $rawLog, '<pre>' ), 'Log preserves developer HTML wrappers exactly.' );
assert_true( false !== strpos( $rawLog, $longValue ), 'Log preserves complete long var_dump strings.' );
assert_true( 0 === strpos( $rawLog, 'WP CRON DEBUG' . PHP_EOL . '--------------------------------------------------------------------' ), 'Log starts with the WP CRON DEBUG heading and a single separator below it.' );
assert_true( strpos( $rawLog, 'RESULT' ) < strpos( $rawLog, 'OUTPUT' ), 'RESULT appears directly after the main metadata section and before OUTPUT.' );
assert_true( false === strpos( $rawLog, '--------------------------------------------------------------------' . PHP_EOL . 'RESULT' ), 'RESULT has no separator above its heading.' );
assert_true( false === strpos( $rawLog, '--------------------------------------------------------------------' . PHP_EOL . 'OUTPUT' ), 'OUTPUT has no separator above its heading.' );
unlink( $rawWriter->getPath() );
rmdir( $tmpRaw );

// Log is overwritten, not appended.
$tmp = sys_get_temp_dir() . '/wp-cron-debug-test-' . uniqid( '', true );
mkdir( $tmp );
$writer = new LogWriter( $tmp );
$event = array(
    'hook' => 'first_hook',
    'category' => SourceClassifier::PLUGINS,
    'source' => 'sample-plugin',
    'callbacks' => array( array( 'name' => 'Sample::run' ) ),
    'schedule' => 'hourly',
    'args' => array( 123 ),
);
$result = array( 'stdout' => "first output\n", 'stderr' => '', 'timed_out' => false, 'truncated' => false, 'exit_code' => 0, 'duration' => 0.1 );
$meta = array( 'status' => 'success', 'fatal' => false, 'started_at' => '2026-09-15 12:00:00 UTC', 'duration' => 0.1, 'peak_memory' => 1024 );
$writer->write( $event, $result, $meta );
$event['hook'] = 'second_hook';
$result['stdout'] = "second output\n";
$writer->write( $event, $result, $meta );
$log = file_get_contents( $writer->getPath() );
assert_true( false === strpos( $log, 'first_hook' ), 'Second run removes previous hook from log.' );
assert_true( false === strpos( $log, 'first output' ), 'Second run removes previous output from log.' );
assert_true( false !== strpos( $log, 'second_hook' ), 'Second run writes current hook.' );
assert_true( false !== strpos( $log, 'second output' ), 'Second run writes current output.' );
assert_same( $tmp . DIRECTORY_SEPARATOR . 'cron-debug.log', $writer->getPath(), 'Log path is exactly cron-debug.log in the command working directory.' );
unlink( $writer->getPath() );
rmdir( $tmp );

// Process runner captures both output streams without a shell.
$runner = new ProcessRunner();
$process = $runner->run(
    array( PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err");' ),
    getcwd(),
    5
);
assert_same( 'out', $process['stdout'], 'Process runner captures stdout.' );
assert_same( 'err', $process['stderr'], 'Process runner captures stderr.' );
assert_true( ! $process['timed_out'], 'Short process does not time out.' );

$timeoutProcess = $runner->run( array( PHP_BINARY, '-r', 'sleep(2);' ), getcwd(), 1 );
assert_true( $timeoutProcess['timed_out'], 'Long child process is terminated at the configured timeout.' );

// Worker captures developer output while preserving a successful result.
$GLOBALS['test_scheduled_event_exists'] = true;
$GLOBALS['test_crons'][123] = array( 'debug_hook' => array( md5( serialize( array( 'captured' ) ) ) => array( 'schedule' => 'hourly', 'args' => array( 'captured' ) ) ) );
$GLOBALS['wp_filter']['debug_hook'] = new class {
    public $callbacks = array( 10 => array( 'x' => array( 'function' => 'strlen' ) ) );
};
$GLOBALS['test_action_callback'] = static function ( $value ) {
    var_dump( $value );
};
$requestFile = tempnam( sys_get_temp_dir(), 'cron-debug-request-' );
$resultFile = tempnam( sys_get_temp_dir(), 'cron-debug-result-' );
file_put_contents( $requestFile, json_encode( array( 'event' => array( 'hook' => 'debug_hook', 'timestamp' => 123, 'args' => array( 'captured' ) ) ) ) );
$worker = new Worker( $requestFile, $resultFile );
ob_start();
$worker->run();
$output = ob_get_clean();
$workerMeta = json_decode( file_get_contents( $resultFile ), true );
assert_true( false !== strpos( $output, 'captured' ), 'Worker allows var_dump output to be captured by the process.' );
assert_same( 'success', $workerMeta['status'], 'Worker records successful execution.' );
unlink( $requestFile );
unlink( $resultFile );

// Missing exact event is not executed.
$GLOBALS['test_crons'][123]['debug_hook'] = array();
$GLOBALS['test_action_callback'] = static function () {
    throw new RuntimeException( 'Should not run.' );
};
$requestFile = tempnam( sys_get_temp_dir(), 'cron-debug-request-' );
$resultFile = tempnam( sys_get_temp_dir(), 'cron-debug-result-' );
file_put_contents( $requestFile, json_encode( array( 'event' => array( 'hook' => 'debug_hook', 'timestamp' => 123, 'args' => array() ) ) ) );
$worker = new Worker( $requestFile, $resultFile );
$worker->run();
$workerMeta = json_decode( file_get_contents( $resultFile ), true );
assert_same( 'missing_event', $workerMeta['status'], 'Worker refuses a stale scheduled event.' );
unlink( $requestFile );
unlink( $resultFile );

// Event-list summaries align the final relative-time column.
$commandReflection = new ReflectionClass( 'WpCronDebug\Command' );
$command = $commandReflection->newInstanceWithoutConstructor();
$summaryMethod = $commandReflection->getMethod( 'buildHookSummaries' );
$summaryMethod->setAccessible( true );
$now = time();
$summaries = $summaryMethod->invoke( $command, array(
    'daily_hook' => array( array( 'source' => 'WordPress Core', 'schedule' => 'daily', 'timestamp' => $now + 3600 ) ),
    'twicedaily_hook' => array( array( 'source' => 'WordPress Core', 'schedule' => 'twicedaily', 'timestamp' => $now + 7200 ) ),
    'weekly_hook' => array( array( 'source' => 'WordPress Core', 'schedule' => 'weekly', 'timestamp' => $now + 10800 ) ),
) );
$lastColumnPositions = array_map( static function ( $summary ) {
    return strrpos( $summary, ' · ' );
}, $summaries );
assert_same( 1, count( array_unique( $lastColumnPositions ) ), 'Relative-time column starts at the same position for different schedule-label lengths.' );

// Inner-menu rendering always exposes Back and global Exit.
WP_CLI::$lines = array();
$console = new Console( false );
$console->renderMenu( 'Plugins', array( '1' => 'sample_hook' ), array(), true );
$menu = implode( "\n", WP_CLI::$lines );
assert_true( false !== strpos( $menu, '[0] Back' ), 'Internal menu includes Back.' );
assert_true( false !== strpos( $menu, '[x] Exit' ), 'Internal menu includes global Exit.' );
assert_same( '✓ OK', $console->statusText( 'ok', '✓ OK' ), 'Status colorization preserves visible text in the test console.' );
assert_same( '! WARN', $console->statusText( 'warning', '! WARN' ), 'Warning colorization preserves visible text.' );
assert_same( '✗ ERROR', $console->statusText( 'error', '✗ ERROR' ), 'Error colorization preserves visible text.' );

// Run-result navigation has Run again, Back, and Exit as fixed UX contract.
$commandSource = file_get_contents( $root . '/src/Command.php' );
assert_true( false !== strpos( $commandSource, 'defined( "DOING_CRON" )' ), 'Worker command defines DOING_CRON before WordPress loads.' );
assert_true( false !== strpos( $commandSource, 'defined( "SAVEQUERIES" )' ), 'Profile worker enables SAVEQUERIES before WordPress loads.' );

$consoleSource = file_get_contents( $root . '/src/Console.php' );
assert_true( false !== strpos( $consoleSource, "'[1] Run again'" ), 'Run-result menu includes Run again.' );
assert_true( false !== strpos( $consoleSource, "'[0] Back'" ), 'Run-result menu includes Back.' );
assert_true( false !== strpos( $consoleSource, "'[x] Exit'" ), 'Run-result menu includes Exit.' );
assert_true( false === strpos( $consoleSource, 'public function confirm' ), 'Real-cron mode no longer needs a confirmation helper.' );
assert_true( false === strpos( $commandSource, 'Continue with the real WP-Cron lifecycle?' ), 'Real-cron mode runs without an additional confirmation prompt.' );
assert_true( false === strpos( $commandSource, "'Duration: ' . number_format" ), 'Console run summaries no longer print duration.' );
assert_true( false !== strpos( $commandSource, "return 'real_complete';" ), 'Real-cron runs return immediately to refreshed event navigation.' );
assert_true( false !== strpos( $commandSource, 'Event list refreshed.' ), 'Real-cron completion leaves a visible refreshed-list status message.' );


// Direct hook lookup returns only the requested scheduled hook and preserves instances.
$GLOBALS['test_crons'] = array(
    200 => array(
        'direct_hook' => array(
            md5( serialize( array( 'a' ) ) ) => array( 'schedule' => 'hourly', 'args' => array( 'a' ), 'interval' => 3600 ),
        ),
        'other_hook' => array(
            md5( serialize( array() ) ) => array( 'schedule' => 'daily', 'args' => array(), 'interval' => 86400 ),
        ),
    ),
    300 => array(
        'direct_hook' => array(
            md5( serialize( array( 'b' ) ) ) => array( 'schedule' => 'daily', 'args' => array( 'b' ), 'interval' => 86400 ),
        ),
    ),
);
$directEvents = $repository->eventsForHook( 'direct_hook' );
assert_same( 2, count( $directEvents ), 'Direct hook lookup returns all scheduled instances for the requested hook.' );
assert_same( 'direct_hook', $directEvents[0]['hook'], 'Direct hook lookup does not include unrelated hooks.' );
assert_same( array(), $repository->eventsForHook( 'missing_hook' ), 'Direct hook lookup returns an empty list for an unscheduled hook.' );

// Details view exposes the event identity and callback source without executing it.
$consoleProperty = $commandReflection->getProperty( 'console' );
$consoleProperty->setAccessible( true );
$consoleProperty->setValue( $command, new Console( false ) );
$detailsMethod = $commandReflection->getMethod( 'showEventDetails' );
$detailsMethod->setAccessible( true );
WP_CLI::$lines = array();
$detailsMethod->invoke( $command, array(
    'hook' => 'details_hook',
    'timestamp' => time() + 3600,
    'args' => array( 'product_id' => 42 ),
    'signature' => md5( serialize( array( 'product_id' => 42 ) ) ),
    'schedule' => 'hourly',
    'interval' => 3600,
    'category' => SourceClassifier::PLUGINS,
    'source' => 'sample-plugin',
    'callbacks' => array( array(
        'name' => 'Sample\\Cron::run',
        'file' => '/srv/www/wp-content/plugins/sample/src/Cron.php',
        'line' => 88,
        'priority' => 10,
        'callable' => true,
    ) ),
) );
$detailsOutput = implode( "\n", WP_CLI::$lines );
assert_true( false !== strpos( $detailsOutput, 'Hook:       details_hook' ), 'Details view shows the hook name.' );
assert_true( false !== strpos( $detailsOutput, 'Sample\\Cron::run' ), 'Details view shows the callback.' );
assert_true( false !== strpos( $detailsOutput, '/srv/www/wp-content/plugins/sample/src/Cron.php:88' ), 'Details view shows callback file and source line.' );
assert_true( false !== strpos( $detailsOutput, '"product_id":42' ), 'Details view shows exact scheduled arguments.' );
assert_true( false !== strpos( $detailsOutput, 'EVENT' ), 'Details view groups event metadata under EVENT.' );
assert_true( false !== strpos( $detailsOutput, 'CALLBACK' ), 'Details view groups callback metadata under CALLBACK.' );
assert_true( false !== strpos( $detailsOutput, 'DIAGNOSTICS' ), 'Details view groups diagnostics under DIAGNOSTICS.' );
assert_true( false !== strpos( $detailsOutput, '✓ OK' ), 'Healthy details show an explicit OK diagnostic status.' );

// Event selection is details-first and the former filter/details selector actions are gone.
$commandSource = file_get_contents( $root . '/src/Command.php' );
assert_true( false !== strpos( $commandSource, '[<hook>]' ), 'Command documents optional direct hook mode.' );
assert_true( false !== strpos( $commandSource, '$this->inspectEventFlow( $event );' ), 'Selected events enter the details-first flow before execution.' );
assert_true( false === strpos( $commandSource, 'Filter cron events' ), 'Category menus no longer expose a filter action.' );
assert_true( false === strpos( $commandSource, 'View event details' ), 'Category menus no longer expose a separate details action.' );
assert_true( false === strpos( $commandSource, 'Event number:' ), 'Details no longer require a second event-number prompt.' );
assert_true( false === strpos( $commandSource, 'filterHooks' ), 'Removed filtering implementation leaves no dead filter helper.' );

$consoleSource = file_get_contents( $root . '/src/Console.php' );
assert_true( false === strpos( $consoleSource, 'promptText' ), 'Removed filtering flow leaves no unused free-text prompt helper.' );
assert_true( false !== strpos( $consoleSource, "'[1] Debug run" ), 'Details screen offers a schedule-preserving debug run.' );
assert_true( false !== strpos( $consoleSource, "'[2] Profile run" ), 'Details screen offers a profile run.' );
assert_true( false !== strpos( $consoleSource, "'[3] Run as real cron" ), 'Details screen offers explicit real-cron lifecycle execution.' );
assert_true( false !== strpos( $consoleSource, "'[0] Back'" ), 'Details screen keeps Back navigation.' );
assert_true( false !== strpos( $consoleSource, "'[x] Exit'" ), 'Details screen keeps global Exit navigation.' );

// Callback reflection retains source line metadata for the details view.
function cron_debug_test_callback_for_line() {}
$GLOBALS['wp_filter']['line_test_hook'] = new class {
    public $callbacks = array();

    public function __construct() {
        $this->callbacks = array(
            10 => array(
                'line-test' => array( 'function' => 'cron_debug_test_callback_for_line' ),
            ),
        );
    }
};
$lineCallbacks = $repository->callbacksForHook( 'line_test_hook' );
assert_true( isset( $lineCallbacks[0]['line'] ) && $lineCallbacks[0]['line'] > 0, 'Callback reflection captures its source line.' );




// Diagnostics stay conservative and report only observable cron/callback problems.
$diagnostics = new EventDiagnostics();
$healthyDiagnostics = $diagnostics->analyze( array(
    'hook' => 'healthy_hook',
    'timestamp' => time() + 3600,
    'signature' => md5( serialize( array() ) ),
    'args' => array(),
    'schedule' => 'hourly',
    'interval' => 3600,
    'callbacks' => array( array( 'callable' => true ) ),
) );
assert_same( EventDiagnostics::OK, $healthyDiagnostics[0]['level'], 'Healthy scheduled event reports no obvious diagnostics issues.' );

$brokenDiagnostics = $diagnostics->analyze( array(
    'hook' => 'broken_hook',
    'timestamp' => time() - 7200,
    'signature' => 'invalid-signature',
    'args' => array( 1 ),
    'schedule' => 'missing_schedule',
    'interval' => 0,
    'callbacks' => array(),
) );
$brokenMessages = implode( "\n", array_map( static function ( $issue ) { return $issue['message']; }, $brokenDiagnostics ) );
assert_true( false !== strpos( $brokenMessages, 'No registered callback' ), 'Diagnostics report a missing callback.' );
assert_true( false !== strpos( $brokenMessages, 'overdue' ), 'Diagnostics report overdue events.' );
assert_true( false !== strpos( $brokenMessages, 'not currently registered' ), 'Diagnostics report unknown recurrence schedules.' );
assert_true( false !== strpos( $brokenMessages, 'no valid interval' ), 'Diagnostics report invalid recurring intervals.' );
assert_true( false !== strpos( $brokenMessages, 'signature does not match' ), 'Diagnostics report inconsistent cron signatures.' );


// Category-list status severity reflects the worst diagnostic state across hook instances.
$statusMethod = $commandReflection->getMethod( 'hookDiagnosticStatus' );
$statusMethod->setAccessible( true );
assert_same( EventDiagnostics::OK, $statusMethod->invoke( $command, array( array(
    'hook' => 'healthy_list_hook',
    'timestamp' => time() + 600,
    'signature' => md5( serialize( array() ) ),
    'args' => array(),
    'schedule' => 'hourly',
    'interval' => 3600,
    'callbacks' => array( array( 'callable' => true ) ),
) ) ), 'Healthy hooks receive OK status in category lists.' );
assert_same( EventDiagnostics::ERROR, $statusMethod->invoke( $command, array( array(
    'hook' => 'broken_list_hook',
    'timestamp' => time() - 3600,
    'signature' => md5( serialize( array() ) ),
    'args' => array(),
    'schedule' => 'hourly',
    'interval' => 3600,
    'callbacks' => array(),
) ) ), 'Hooks with a missing callback receive ERROR status in category lists.' );
assert_same( EventDiagnostics::WARNING, $statusMethod->invoke( $command, array( array(
    'hook' => 'late_list_hook',
    'timestamp' => time() - 600,
    'signature' => md5( serialize( array() ) ),
    'args' => array(),
    'schedule' => 'hourly',
    'interval' => 3600,
    'callbacks' => array( array( 'callable' => true ) ),
) ) ), 'Overdue callable hooks receive WARN status in category lists.' );

$formatStatusMethod = $commandReflection->getMethod( 'formatStatusColumn' );
$formatStatusMethod->setAccessible( true );
assert_same( 12, strlen( $formatStatusMethod->invoke( $command, EventDiagnostics::OK ) ), 'OK status column has a stable visible width without color tokens in tests.' );
assert_same( 12, strlen( $formatStatusMethod->invoke( $command, EventDiagnostics::WARNING ) ), 'WARN status column has a stable visible width.' );
assert_same( 12, strlen( $formatStatusMethod->invoke( $command, EventDiagnostics::ERROR ) ), 'ERROR status column has a stable visible width.' );

// Profile log adds bounded DB/memory profiling without replacing normal output.
$tmpProfile = sys_get_temp_dir() . '/wp-cron-debug-profile-test-' . uniqid( '', true );
mkdir( $tmpProfile );
$profileWriter = new LogWriter( $tmpProfile );
$profileEvent = array(
    'hook' => 'profile_hook',
    'category' => SourceClassifier::PLUGINS,
    'source' => 'sample-plugin',
    'callbacks' => array( array( 'name' => 'Sample::profile' ) ),
    'schedule' => 'hourly',
    'args' => array(),
);
$profileResult = array( 'stdout' => 'profile output', 'stderr' => '', 'timed_out' => false, 'truncated' => false, 'exit_code' => 0, 'duration' => 0.2 );
$profileMeta = array(
    'status' => 'success',
    'fatal' => false,
    'started_at' => '2026-09-16 08:00:00 UTC',
    'duration' => 0.2,
    'peak_memory' => 2097152,
    'mode' => 'profile',
    'profile' => array(
        'query_count' => 3,
        'query_time' => 0.0123,
        'query_details_available' => true,
        'memory_delta' => 1048576,
        'queries' => array(
            array( 'duration' => 0.0100, 'sql' => 'SELECT * FROM wp_posts', 'caller' => 'get_posts' ),
            array( 'duration' => 0.0023, 'sql' => 'SELECT option_value FROM wp_options', 'caller' => 'get_option' ),
        ),
    ),
);
$profileWriter->write( $profileEvent, $profileResult, $profileMeta );
$profileLog = file_get_contents( $profileWriter->getPath() );
assert_true( false !== strpos( $profileLog, 'PROFILE' ), 'Profile run writes a dedicated PROFILE section.' );
assert_true( false !== strpos( $profileLog, 'SUMMARY' ), 'Profile log separates summary metrics from query details.' );
assert_true( false !== strpos( $profileLog, 'DB queries:   3' ), 'Profile log records callback query count.' );
assert_true( false !== strpos( $profileLog, 'Memory delta: +1.0 MB' ), 'Profile log records callback memory delta.' );
assert_true( false !== strpos( $profileLog, 'TOP 10 QUERIES BY DURATION' ), 'Profile log labels the top-ten query-duration subsection without implying an absolute performance threshold.' );
assert_true( false !== strpos( $profileLog, '[1]  0.0100 s' ), 'Profile log gives each query a numbered timing block with readable spacing.' );
assert_true( false !== strpos( $profileLog, '    Caller: get_posts' ), 'Profile query blocks align caller metadata.' );
assert_true( false !== strpos( $profileLog, '    Query:  SELECT * FROM wp_posts' ), 'Profile query blocks align SQL text.' );
assert_true( false !== strpos( $profileLog, '[2]  0.0023 s' ), 'Profile log separates multiple query blocks.' );
assert_true( false !== strpos( $profileLog, '    Query:  SELECT option_value FROM wp_options' ), 'Profile log preserves each bounded query.' );
assert_true( false === strpos( $profileLog, 'SLOWEST QUERIES' ), 'Profile output does not label every ranked query as slow.' );
assert_true( false === strpos( $profileLog, '--------------------------------------------------------------------' . PHP_EOL . 'PROFILE' ), 'PROFILE has no separator above its heading.' );
unlink( $profileWriter->getPath() );
rmdir( $tmpProfile );


// Worker profile captures callback-local DB query count/time and memory metadata.
if ( ! defined( 'SAVEQUERIES' ) ) {
    define( 'SAVEQUERIES', true );
}
$GLOBALS['test_crons'] = array(
    444 => array(
        'profile_worker_hook' => array(
            md5( serialize( array() ) ) => array( 'schedule' => 'hourly', 'args' => array(), 'interval' => 3600 ),
        ),
    ),
);
$GLOBALS['wp_filter']['profile_worker_hook'] = new class {
    public $callbacks = array( 10 => array( 'profile' => array( 'function' => 'strlen' ) ) );
};
$GLOBALS['wpdb'] = new stdClass();
$GLOBALS['wpdb']->num_queries = 10;
$GLOBALS['wpdb']->queries = array_fill( 0, 10, array( 'SELECT bootstrap', 0.001, 'bootstrap' ) );
$GLOBALS['test_action_callback'] = static function () {
    $GLOBALS['wpdb']->num_queries += 12;
    for ( $i = 1; $i <= 12; $i++ ) {
        $GLOBALS['wpdb']->queries[] = array(
            'SELECT profile_query_' . $i,
            ( 13 - $i ) / 1000,
            'profile_callback'
        );
    }
};
$requestFile = tempnam( sys_get_temp_dir(), 'cron-debug-request-' );
$resultFile = tempnam( sys_get_temp_dir(), 'cron-debug-result-' );
file_put_contents( $requestFile, json_encode( array(
    'mode' => 'profile',
    'event' => array( 'hook' => 'profile_worker_hook', 'timestamp' => 444, 'args' => array(), 'schedule' => 'hourly', 'interval' => 3600 ),
) ) );
$worker = new Worker( $requestFile, $resultFile );
$worker->run();
$workerMeta = json_decode( file_get_contents( $resultFile ), true );
assert_same( 12, $workerMeta['profile']['query_count'], 'Profile worker counts only queries executed after the callback profile baseline.' );
assert_true( abs( $workerMeta['profile']['query_time'] - 0.078 ) < 0.00001, 'Profile worker sums callback query durations.' );
assert_same( 10, count( $workerMeta['profile']['queries'] ), 'Profile worker keeps only the top 10 queries by duration.' );
assert_same( 'SELECT profile_query_1', $workerMeta['profile']['queries'][0]['sql'], 'Profile worker sorts query details by duration.' );
assert_same( 'SELECT profile_query_10', $workerMeta['profile']['queries'][9]['sql'], 'Profile worker retains the tenth-ranked query.' );
assert_true( $workerMeta['profile']['query_details_available'], 'Profile worker reports query details when SAVEQUERIES was active before execution.' );
unlink( $requestFile );
unlink( $resultFile );

// Real cron mode applies WordPress' reschedule -> unschedule -> callback ordering.
$GLOBALS['test_lifecycle_calls'] = array();
$GLOBALS['test_crons'] = array(
    555 => array(
        'real_hook' => array(
            md5( serialize( array( 'x' ) ) ) => array( 'schedule' => 'hourly', 'args' => array( 'x' ), 'interval' => 3600 ),
        ),
    ),
);
$GLOBALS['wp_filter']['real_hook'] = new class {
    public $callbacks = array( 10 => array( 'real' => array( 'function' => 'strlen' ) ) );
};
$GLOBALS['test_action_callback'] = static function ( $value ) {
    $GLOBALS['test_lifecycle_calls'][] = array( 'callback', $value );
};
$requestFile = tempnam( sys_get_temp_dir(), 'cron-debug-request-' );
$resultFile = tempnam( sys_get_temp_dir(), 'cron-debug-result-' );
file_put_contents( $requestFile, json_encode( array(
    'mode' => 'real',
    'event' => array( 'hook' => 'real_hook', 'timestamp' => 555, 'args' => array( 'x' ), 'schedule' => 'hourly', 'interval' => 3600 ),
) ) );
$worker = new Worker( $requestFile, $resultFile );
$worker->run();
$workerMeta = json_decode( file_get_contents( $resultFile ), true );
assert_same( 'reschedule', $GLOBALS['test_lifecycle_calls'][0][0], 'Real cron mode reschedules recurring event first.' );
assert_same( 'unschedule', $GLOBALS['test_lifecycle_calls'][1][0], 'Real cron mode unschedules the current event second.' );
assert_same( 'callback', $GLOBALS['test_lifecycle_calls'][2][0], 'Real cron mode executes the callback after schedule lifecycle operations.' );
assert_same( 'success', $workerMeta['lifecycle']['rescheduled'], 'Worker records successful reschedule operation.' );
assert_same( 'success', $workerMeta['lifecycle']['unscheduled'], 'Worker records successful unschedule operation.' );
unlink( $requestFile );
unlink( $resultFile );

// Real lifecycle log is explicit that the schedule was modified.
$tmpReal = sys_get_temp_dir() . '/wp-cron-debug-real-test-' . uniqid( '', true );
mkdir( $tmpReal );
$realWriter = new LogWriter( $tmpReal );
$realMeta = array(
    'status' => 'success',
    'fatal' => false,
    'started_at' => '2026-09-16 08:00:00 UTC',
    'duration' => 0.1,
    'peak_memory' => 1024,
    'mode' => 'real',
    'lifecycle' => array( 'rescheduled' => 'success', 'unscheduled' => 'success' ),
);
$realWriter->write( $profileEvent, $profileResult, $realMeta );
$realLog = file_get_contents( $realWriter->getPath() );
assert_true( false !== strpos( $realLog, 'Real cron lifecycle / schedule updated' ), 'Real cron log clearly identifies schedule-changing mode.' );
assert_true( false !== strpos( $realLog, 'CRON LIFECYCLE' ), 'Real cron log includes lifecycle results.' );
assert_true( false !== strpos( $realLog, 'Rescheduled: yes' ), 'Real cron log records reschedule result.' );
assert_true( false === strpos( $realLog, '--------------------------------------------------------------------' . PHP_EOL . 'CRON LIFECYCLE' ), 'CRON LIFECYCLE has no separator above its heading.' );
unlink( $realWriter->getPath() );
rmdir( $tmpReal );

// Interactive screen replacement is enabled only when terminal control is safe.
$screenMethod = new ReflectionMethod( 'WpCronDebug\\Console', 'supportsScreenControl' );
$screenMethod->setAccessible( true );
$previousTerm = getenv( 'TERM' );
putenv( 'TERM=xterm-256color' );
$nonInteractiveConsole = new Console( false );
assert_true( ! $screenMethod->invoke( $nonInteractiveConsole ), 'Non-interactive output never uses ANSI screen replacement.' );
if ( '\\' !== DIRECTORY_SEPARATOR ) {
    $interactiveConsole = new Console( true );
    assert_true( $screenMethod->invoke( $interactiveConsole ), 'Interactive Unix-like terminals support ANSI screen replacement.' );
}
putenv( 'TERM=dumb' );
$dumbConsole = new Console( true );
assert_true( ! $screenMethod->invoke( $dumbConsole ), 'TERM=dumb disables ANSI screen replacement.' );
if ( false === $previousTerm ) {
    putenv( 'TERM' );
} else {
    putenv( 'TERM=' . $previousTerm );
}

$commandSource = file_get_contents( $root . '/src/Command.php' );
assert_true( substr_count( $commandSource, '->clearScreen();' ) >= 6, 'Navigation clears the visible screen at each major interactive context transition.' );

fwrite( STDOUT, sprintf( "%d tests, %d failures\n", $tests, $failures ) );
exit( $failures > 0 ? 1 : 0 );
