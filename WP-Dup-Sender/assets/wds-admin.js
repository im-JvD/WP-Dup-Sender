
// wds-admin.js
(function($){
    $(document).ready(function(){
        $('.wds-tab-link').off('click').on('click', function(){
            $('.wds-tab-link').removeClass('active');
            $(this).addClass('active');
            var tab = $(this).data('tab');
            $('.wds-panel').removeClass('active');
            $('#tab-'+tab).addClass('active');
        });

        $('#wds-save-btn').off('click').on('click', function(e){
            e.preventDefault();
            $('#wds-submit').click();
        });

        $('#wds-send-test').off('click').on('click', function(e){
            e.preventDefault();
            $('#wds-test-result').text('در حال ارسال...');
            $.post(ajaxurl, { action: 'wds_send_test', _wpnonce: wds_ajax.nonce }, function(r){
                $('#wds-test-result').text((r && r.data) ? r.data : 'خطا');
            });
        });

        $('#wds-send-public').off('click').on('click', function(e){
            e.preventDefault();
            $('#wds-public-result').text('در حال ارسال...');
            $.post(ajaxurl, { action: 'wds_manual_public', message: $('#wds-public-msg').val(), _wpnonce: wds_ajax.nonce }, function(r){
                $('#wds-public-result').text((r && r.data) ? r.data : 'خطا');
            });
        });

        $('.wds-send-file').off('click').on('click', function(e){
            e.preventDefault();
            var file = $(this).data('file');
            if(!confirm('آیا مطمئن هستید می‌خواهید این فایل را ارسال کنید؟')) return;
            $(this).text('در حال ارسال...');
            $.post(ajaxurl, { action: 'wds_send_file', file: file, _wpnonce: wds_ajax.nonce }, function(r){
                alert((r && r.data) ? r.data : 'خطا');
                location.reload();
            });
        });
    });
})(jQuery);
