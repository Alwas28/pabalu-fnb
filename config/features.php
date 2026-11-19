<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hak Akses Role yang Bisa Diatur Owner
    |--------------------------------------------------------------------------
    | Saat mati (bawaan): menu & halaman "Hak Akses Role" disembunyikan dan setiap
    | role memakai izin bawaannya, mengabaikan businesses.role_permissions.
    */

    'role_permissions' => (bool) env('FEATURE_ROLE_PERMISSIONS', false),

];
