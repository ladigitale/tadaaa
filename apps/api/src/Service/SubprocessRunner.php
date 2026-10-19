<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Lance une commande (sans shell), lui envoie `$stdin`, borne durée et sortie.
 * Utilisé par les outils MCP qui exécutent du Node (simulate_store, render_artifact).
 */
final class SubprocessRunner
{
    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     *
     * @return array{status: 'ok'|'timeout'|'toolarge'|'spawn', stdout: string, stderr: string}
     */
    public static function run(array $cmd, string $cwd, string $stdin, int $timeoutS, int $maxOutput, array $env = []): array
    {
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!\is_resource($proc)) {
            return ['status' => 'spawn', 'stdout' => '', 'stderr' => ''];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $out = '';
        $err = '';
        $status = 'ok';
        $deadline = microtime(true) + $timeoutS;
        while (true) {
            $out .= (string) fread($pipes[1], 65536);
            $err .= (string) fread($pipes[2], 8192);
            if (!proc_get_status($proc)['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $deadline) {
                $status = 'timeout';
                proc_terminate($proc, 9);
                break;
            }
            if (\strlen($out) > $maxOutput) {
                $status = 'toolarge';
                proc_terminate($proc, 9);
                break;
            }
            usleep(5000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if ($status === 'ok' && \strlen($out) > $maxOutput) {
            $status = 'toolarge';
        }

        return ['status' => $status, 'stdout' => $out, 'stderr' => $err];
    }
}
