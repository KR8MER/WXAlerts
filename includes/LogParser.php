<?php
// includes/LogParser.php

class LogParser {
    private $logPath;
    private $maxLines;

    public function __construct($logPath, $maxLines = 100) {
        $this->logPath = $logPath;
        $this->maxLines = $maxLines;
    }

    public function getRecentLogs() {
        if (!file_exists($this->logPath)) {
            return [];
        }

        $logs = [];
        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines); // Most recent first

        foreach (array_slice($lines, 0, $this->maxLines) as $line) {
            $log = $this->parseLine($line);
            if ($log) {
                $logs[] = $log;
            }
        }

        return $logs;
    }

    private function parseLine($line) {
        // Standard PHP error log format
        if (preg_match('/\[([^\]]+)\] (.+)/', $line, $matches)) {
            $level = 'INFO';
            if (stripos($line, 'error') !== false) {
                $level = 'ERROR';
            } elseif (stripos($line, 'warning') !== false) {
                $level = 'WARNING';
            }

            return [
                'timestamp' => $matches[1],
                'message' => $matches[2],
                'level' => $level
            ];
        }
        return null;
    }
}