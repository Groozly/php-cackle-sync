php-cackle-sync
===============

PHP Cackle Sync Comments

How it works:

1. Initialize Cackle.

2. If timer > 0, all comments from cackle.me 
will be saved to your database.

3. Cackle template (javascript and container) and comments from 
local database will be displayed on your page (for better SEO 
optimization).

4. If your have cron, schedule sync manually 
(and set timer = 0 while creating Cackle instance).


Example
-------

Initialize Cackle

    $pdo = new PDO('mysql:host=localhost;dbname=cackle;charset=cp1251', 'user', 'password');
    $cackle = new Cackle(11111, $pdo, 'accountApiKey', 'siteApiKey', 0, 'cp1251');
    

If you have no cron, initialize Cackle with timer > 0

    // Sync comments with cackle.me once in 120 seconds
    $cackle = new Cackle(11111, $pdo, 'accountApiKey', 'siteApiKey', 120, 'cp1251');
    
    
Get Cackle code (JS and container) and comments from local DB

    echo $cackle->showComments('cackletest');


Sync local comments with Cackle.me (scheduling it to run in your webserver cron)

    $cackle->syncComments();