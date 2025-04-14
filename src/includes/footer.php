<footer style="text-align: center; padding: 20px; margin-top: 40px; color: #666; font-size: 0.8em; background: #f9f9f9; border-top: 1px solid #eee; position: relative; width: 100%;">
    <p>© 2025 Aidan Hall. All rights reserved.</p>
    <p>Sweating Dingers is unaffiliated with Underdog Fantasy. All stats are unofficial.</p>
</footer>

<script>
// Ensure footer is at the bottom of the page
document.addEventListener('DOMContentLoaded', function() {
    const footer = document.querySelector('footer');
    const body = document.body;
    const html = document.documentElement;
    
    function adjustFooter() {
        const bodyHeight = Math.max(body.scrollHeight, body.offsetHeight);
        const windowHeight = window.innerHeight;
        
        if (bodyHeight < windowHeight) {
            footer.style.position = 'absolute';
            footer.style.bottom = '0';
        } else {
            footer.style.position = 'relative';
            footer.style.bottom = 'auto';
        }
    }
    
    // Run on load and resize
    adjustFooter();
    window.addEventListener('resize', adjustFooter);

    // Handle tab changes
    const tabs = document.querySelectorAll('.tab');
    if (tabs.length > 0) {
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Small delay to allow content to be shown
                setTimeout(adjustFooter, 100);
            });
        });
    }
});
</script> 