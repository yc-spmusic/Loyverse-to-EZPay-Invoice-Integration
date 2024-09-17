<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "POST 請求成功接收";
} else {
    echo "非 POST 請求";
}
?>
