<?php

namespace App\Utils;

use RuntimeException;

/** Read the shipped MySQL scripts, including quoted semicolons and DELIMITER. */
class SqlFile
{
    public static function statements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $delimiter = ';';
        $quote = null;
        $comment = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($comment === 'line') {
                if ($char === "\n") { $comment = null; $buffer .= "\n"; }
                continue;
            }
            if ($comment === 'block') {
                if ($char === '*' && $next === '/') { $comment = null; $i++; $buffer .= ' '; }
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $length) { $buffer .= $sql[++$i]; continue; }
                if ($char === $quote) {
                    if ($next === $quote) { $buffer .= $sql[++$i]; } else { $quote = null; }
                }
                continue;
            }
            if (trim($buffer) === '' && preg_match('/\GDELIMITER[ \t]+(\S+)[^\r\n]*/Ai', $sql, $match, 0, $i)) {
                $delimiter = $match[1];
                $i += strlen($match[0]) - 1;
                $buffer = '';
                continue;
            }
            if (($char === '-' && $next === '-' && ctype_space($sql[$i + 2] ?? ' ')) || $char === '#') {
                $comment = 'line';
                continue;
            }
            if ($char === '/' && $next === '*') { $comment = 'block'; $i++; continue; }
            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                if (trim($buffer) !== '') $statements[] = trim($buffer);
                $buffer = '';
                $i += strlen($delimiter) - 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') $quote = $char;
            $buffer .= $char;
        }
        if ($quote !== null || $comment === 'block') throw new RuntimeException('Unterminated SQL string or comment.');
        if (trim($buffer) !== '') $statements[] = trim($buffer);
        return $statements;
    }
}
