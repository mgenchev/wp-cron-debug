<?php

namespace WpCronDebug;

final class OutputFormatter {
    private const MAX_VAR_DUMP_STRING_BYTES = 2048;
    private const VAR_DUMP_HEAD_BYTES = 1536;
    private const VAR_DUMP_TAIL_BYTES = 512;

    public function format( $output ) {
        $output = (string) $output;
        $preWrappersRemoved = 0;
        $longStringsCompacted = 0;

        $output = preg_replace( '/<\/?pre(?:\s[^>]*)?>/i', '', $output, -1, $preWrappersRemoved );
        $output = $this->compactVarDumpStrings( $output, $longStringsCompacted );

        return array(
            'output' => $output,
            'compacted' => $preWrappersRemoved > 0 || $longStringsCompacted > 0,
            'pre_wrappers_removed' => $preWrappersRemoved,
            'long_strings_compacted' => $longStringsCompacted,
        );
    }

    private function compactVarDumpStrings( $output, &$compactedCount ) {
        $length = strlen( $output );
        $offset = 0;
        $result = '';

        while ( $offset < $length && preg_match( '/string\((\d+)\) "/', $output, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
            $matchText = $matches[0][0];
            $matchOffset = $matches[0][1];
            $declaredLength = (int) $matches[1][0];
            $valueStart = $matchOffset + strlen( $matchText );
            $valueEnd = $valueStart + $declaredLength;

            if ( $valueEnd >= $length || '"' !== $output[ $valueEnd ] ) {
                $result .= substr( $output, $offset, ( $matchOffset - $offset ) + strlen( $matchText ) );
                $offset = $valueStart;
                continue;
            }

            $result .= substr( $output, $offset, $valueStart - $offset );
            $value = substr( $output, $valueStart, $declaredLength );

            if ( $declaredLength > self::MAX_VAR_DUMP_STRING_BYTES ) {
                $omitted = $declaredLength - self::MAX_VAR_DUMP_STRING_BYTES;
                $result .= substr( $value, 0, self::VAR_DUMP_HEAD_BYTES );
                $result .= PHP_EOL . '... [cron-debug omitted ' . $omitted . ' bytes from this var_dump string] ...' . PHP_EOL;
                $result .= substr( $value, -self::VAR_DUMP_TAIL_BYTES );
                $compactedCount++;
            } else {
                $result .= $value;
            }

            $result .= '"';
            $offset = $valueEnd + 1;
        }

        if ( $offset < $length ) {
            $result .= substr( $output, $offset );
        }

        return $result;
    }
}
