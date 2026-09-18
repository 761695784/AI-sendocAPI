<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    private function checkAdmin()
    {
        if (! Auth::user()?->hasRole('Admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Administrateur requis.',
            ], 403);
        }
        return null;
    }

    /**
     * Lancer un backup manuellement
     */
    public function runBackup()
    {
        if ($denied = $this->checkAdmin()) {
            return $denied;
        }

        try {
            // ← Lance en arrière-plan au lieu de bloquer la requête
            dispatch(function () {
                Artisan::call('backup:run');
            })->afterResponse();

            activity()
                ->causedBy(Auth::user())
                ->withProperties([
                    'triggered_at' => now()->toDateTimeString(),
                    'type'         => 'manual',
                ])
                ->log('Backup manuel déclenché par un administrateur');

            // ← Répond immédiatement sans attendre la fin du backup
            return response()->json([
                'success' => true,
                'message' => 'Sauvegarde lancée en arrière-plan. Actualisez la liste dans quelques instants.',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur backup manuel', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Liste des backups disponibles
     */
    public function listBackups()
    {
        if ($denied = $this->checkAdmin()) {
            return $denied;
        }

        try {
            $appName    = config('app.name', 'DocTrack');
            $backupPath = storage_path('app' . DIRECTORY_SEPARATOR . $appName);
            $backups    = [];

            if (is_dir($backupPath)) {
                $files = array_diff(scandir($backupPath), ['.', '..']);

                foreach ($files as $file) {
                    if (str_ends_with($file, '.zip')) {
                        $fullPath  = $backupPath . DIRECTORY_SEPARATOR . $file;
                        $backups[] = [
                            'name'       => $file,
                            'size_bytes' => filesize($fullPath),
                            'size_mb'    => round(
                                filesize($fullPath) / 1024 / 1024, 2
                            ),
                            'created_at' => date(
                                'Y-m-d H:i:s', filemtime($fullPath)
                            ),
                        ];
                    }
                }

                usort($backups, fn($a, $b) =>
                    strtotime($b['created_at']) - strtotime($a['created_at'])
                );
            }

            $totalBytes = array_sum(array_column($backups, 'size_bytes'));

            return response()->json([
                'success'       => true,
                'backups'       => $backups,
                'total'         => count($backups),
                'total_size_mb' => round($totalBytes / 1024 / 1024, 2),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Statut de santé des backups
     */
    public function backupStatus()
    {
        if ($denied = $this->checkAdmin()) {
            return $denied;
        }

        try {
            Artisan::call('backup:monitor');
            $output = iconv('UTF-8', 'UTF-8//IGNORE', Artisan::output());

            return response()->json([
                'success' => true,
                'status'  => trim($output) ?: 'Tous les backups sont sains.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => iconv('UTF-8', 'UTF-8//IGNORE', $e->getMessage()),
            ], 500);
        }
    }
    /**
     * Nettoyer les vieux backups
     */
    public function cleanBackups()
    {
        if ($denied = $this->checkAdmin()) {
            return $denied;
        }

        try {
            Artisan::call('backup:clean');
            $output = iconv('UTF-8', 'UTF-8//IGNORE', Artisan::output());

            activity()
                ->causedBy(Auth::user())
                ->log('Nettoyage des backups déclenché manuellement');

            return response()->json([
                'success' => true,
                'message' => 'Nettoyage effectué.',
                'output'  => trim($output),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => iconv('UTF-8', 'UTF-8//IGNORE', $e->getMessage()),
            ], 500);
        }
    }

    /**
     * Télécharger un backup
     */
    public function downloadBackup(Request $request)
    {
        if ($denied = $this->checkAdmin()) {
            return $denied;
        }

        $filename = $request->query('file');

        if (! $filename || ! str_ends_with($filename, '.zip')) {
            return response()->json([
                'success' => false,
                'message' => 'Nom de fichier invalide.',
            ], 422);
        }

        // Sécurité anti path-traversal
        $filename = basename($filename);
        $appName  = config('app.name', 'DocTrack');
        $path     = storage_path(
            'app' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . $filename
        );

        if (! file_exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier introuvable.',
            ], 404);
        }

        activity()
            ->causedBy(Auth::user())
            ->withProperties(['filename' => $filename])
            ->log('Téléchargement d\'un backup');

        return response()->download($path, $filename);
    }
}
