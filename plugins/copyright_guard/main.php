<?php
if ($hook === 'footer_end') {
    $nt = json_encode((string) ($settings['notice_text'] ?? ''), JSON_UNESCAPED_UNICODE);
    $br = ((string) ($settings['block_right'] ?? '0') === '1') ? 'true' : 'false';
    echo <<<HTML
<script type="text/javascript">
(function(){
    if ({$br}) {
        document.oncontextmenu = function(){ return false; };
        document.ondragstart   = function(){ return false; };
    }
    document.oncopy = function(){
        var sel = window.getSelection ? window.getSelection().toString() : '';
        if (sel && sel.length > 20 && window.event && window.event.clipboardData) {
            window.event.clipboardData.setData('text/plain', sel + '\r\n' + {$nt});
            window.event.preventDefault();
        }
    };
})();
</script>
HTML;
}