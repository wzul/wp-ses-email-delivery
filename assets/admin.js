jQuery(document).ready(function($) {
    // Handle details modal
    $('.view-details').on('click', function() {
        var id = $(this).data('id');
        
        // Show loading
        $('#ses-details-content').html('Loading...');
        $('#ses-details-modal').show();
        
        // AJAX request to get details
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ses_get_notification_details',
                id: id,
                nonce: ses_tracker.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#ses-details-content').html(response.data);
                } else {
                    $('#ses-details-content').html('Error loading details.');
                }
            },
            error: function() {
                $('#ses-details-content').html('Error loading details.');
            }
        });
    });
    
    // Close modal
    $('.ses-modal-close, .ses-modal').on('click', function(e) {
        if (e.target === this || $(e.target).hasClass('ses-modal-close')) {
            $('#ses-details-modal').hide();
        }
    });
    
    // Prevent modal content from closing modal when clicked
    $('.ses-modal-content').on('click', function(e) {
        e.stopPropagation();
    });
});