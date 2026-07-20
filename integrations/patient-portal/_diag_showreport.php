<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = '019f4c57-f6dd-7166-8789-9708c78a5dbb';
$user = DB::table('users')->where('id', 1631)->first();
Auth::loginUsingId($user->id);

try {
  $ctrl = $app->make(App\Http\Controllers\StudyController::class);
  $response = $ctrl->showReport($id);
  if (method_exists($response, 'getTargetUrl')) {
    echo 'REDIRECT=' . $response->getTargetUrl() . PHP_EOL;
    echo 'STATUS=' . $response->getStatusCode() . PHP_EOL;
  } else {
    echo 'CLASS=' . get_class($response) . PHP_EOL;
    if (method_exists($response, 'getContent')) {
      echo 'SNIP=' . substr(strip_tags($response->getContent()), 0, 300) . PHP_EOL;
    }
  }
} catch (Throwable $e) {
  echo 'EXCEPTION=' . $e->getMessage() . PHP_EOL;
  echo 'AT=' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
