<?php

use Illuminate\Support\Facades\DB;

test('a sqlite transaction takes the write lock when it begins', function () {
    $file = tempnam(sys_get_temp_dir(), 'snuggy').'.sqlite';
    touch($file);
    config(['database.connections.sqlite_file' => array_merge(
        config('database.connections.sqlite'),
        ['database' => $file],
    )]);
    $connection = DB::connection('sqlite_file');
    $connection->statement('create table probe (id integer)');

    $other = new PDO('sqlite:'.$file);
    $other->setAttribute(PDO::ATTR_TIMEOUT, 0);
    $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        $connection->transaction(function () use ($other) {
            // No statement has run inside the transaction yet: only BEGIN IMMEDIATE holds the lock.
            expect(fn () => $other->exec('insert into probe values (1)'))
                ->toThrow(PDOException::class, 'database is locked');
        });
    } finally {
        $other = null;
        DB::purge('sqlite_file');
        @unlink($file);
        @unlink($file.'-wal');
        @unlink($file.'-shm');
    }
});
