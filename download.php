<?php

/*
|--------------------------------------------------------------------------
| Run Arshwell Framework For Uploaded Files
|--------------------------------------------------------------------------
|
| Used for web requests towards uploaded files (png, jpg, gif, etc).
| So access to certain files can be restricted in necessary situations.
| These requests come here thanks to the uploads/files/.htaccess file.
|
| The other requests goes to index.php, thanks to the root .htaccess file.
|
*/

require("vendor/arshwell/monolith/bootstrap/download.php");
