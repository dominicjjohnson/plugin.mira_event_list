jQuery(document).ready(function($) {
    // Media uploader for event logo
    var mediaUploader;
    
    $('#upload-event-logo').click(function(e) {
        e.preventDefault();
        
        // If the uploader object has already been created, reopen the dialog
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        // Create the media uploader
        mediaUploader = wp.media({
            title: 'Choose Event Logo',
            button: {
                text: 'Choose Logo'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });
        
        // When a file is selected, grab the URL and set it as the text field's value
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            
            // Set the attachment ID
            $('#event_logo').val(attachment.id);
            
            // Create image preview
            var imgHtml = '<img src="' + attachment.url + '" id="event-logo-preview" style="max-width: 250px; height: auto;" />';
            $('#event-logo-container').html(imgHtml);
            
            // Show remove button
            $('#remove-event-logo').show();
        });
        
        // Open the uploader dialog
        mediaUploader.open();
    });
    
    // Remove event logo
    $('#remove-event-logo').click(function(e) {
        e.preventDefault();
        
        // Clear the attachment ID
        $('#event_logo').val('');
        
        // Remove image preview
        $('#event-logo-container').empty();
        
        // Hide remove button
        $(this).hide();
    });
});
