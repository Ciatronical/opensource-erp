<?php
// api.call.php
$data = json_decode(file_get_contents('php://input'), true);
if(null != $data) $_POST = array_merge($_POST, $data);
else $data = array_merge($_POST, $_GET);

if(isset($_POST['content-type']) && $_POST['content-type'] == 'application/pdf') {
    header('Content-Type: application/pdf');
}
else {
    header('Content-Type: application/json');
}

if(isset($data['action'])) {
    $action = $data['action'];
    ( $action and function_exists( $action ) ) or die( resultInfo(false, "Action or function `'.$action.'` not defined!") );
    try {
        $action($data);
    }
    catch (PDOException $e) {
        header('Content-Type: application/json');
        resultInfo(false, "API_DATABASE_ERROR", dbFriendlyError($e));
    }
    catch (ApiError $e) {
        header('Content-Type: application/json');
        resultInfo(false, $e->getId(), $e->getMessage());
        if(!is_null($e->getQuery())) writeLog($e->getQuery());
    }
    catch (\Throwable $e) {
        header('Content-Type: application/json');
        resultInfo(false, 'API_INTERNAL_ERROR: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
else {
    resultInfo(false, 'API_ACTION_NOT_SPECIFIED', 'No action specified');
}
