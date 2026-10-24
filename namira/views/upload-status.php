<?php
// Shared upload status; JavaScript fills in live transfer bytes and server-processing state.
?>
<div class="upload-status" data-upload-status hidden aria-live="polite" aria-atomic="true">
    <p data-upload-message>آماده ارسال</p>
    <progress data-upload-progress max="100" value="0" aria-label="درصد ارسال فایل‌ها"></progress>
    <p class="hint" data-upload-bytes></p>
</div>
