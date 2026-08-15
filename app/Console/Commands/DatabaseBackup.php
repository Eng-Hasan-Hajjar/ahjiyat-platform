<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DatabaseBackup extends Command
{

    protected $signature = 'database:backup';

    protected $description = 'Create daily database backup';


    public function handle()
    {

        $database = config('database.connections.mysql.database');

        $filename = 'backup_' . date('Y_m_d_H_i_s') . '.sql';

        $path = storage_path('app/backups');

        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }

        $fullPath = $path . '/' . $filename;

        $command = "mysqldump --user=" . env('DB_USERNAME') .
            " --password=" . env('DB_PASSWORD') .
            " --host=" . env('DB_HOST') .
            " " . $database .
            " > " . $fullPath;

        exec($command);

        $this->info('Database backup created: ' . $filename);

    }
}