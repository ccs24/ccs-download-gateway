// Auto-copy on click for link inputs
document.addEventListener('DOMContentLoaded', function() {
    
    // Copy to clipboard
    document.querySelectorAll('.link-input, .ccs-link-copy').forEach(function(input) {
        input.addEventListener('click', function() {
            this.select();
            document.execCommand('copy');
            
            // Visual feedback
            var originalBorder = this.style.border;
            this.style.border = '2px solid #27ae60';
            
            setTimeout(function() {
                input.style.border = originalBorder;
            }, 1000);
        });
    });
    
    // File size validation
    var fileInput = document.getElementById('file_upload');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            var file = this.files[0];
            if (file && file.size > 52428800) { // 50MB
                alert('Plik jest za duży! Maksymalny rozmiar to 50MB.');
                this.value = '';
            }
        });
    }
    
});
