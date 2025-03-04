<?php
require_once __DIR__ . '/../src/db.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sweating Dingers | Support</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css">
    <style>
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .support-options {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin: 40px 0;
        }
        
        .support-option {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            width: 300px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .support-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .support-button {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            background-color: #0070ba; /* PayPal blue */
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            transition: background-color 0.2s;
        }
        
        .support-button:hover {
            background-color: #005ea6;
        }
        
        @media (max-width: 768px) {
            .support-options {
                flex-direction: column;
                align-items: center;
            }
            
            .support-option {
                width: 90%;
                max-width: 400px;
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>

    <div class="container">
        <h1>Support Sweating Dingers</h1>
        <p>This site has been an idea in my head for some time now and I'm incredibly excited to be able to share it with the community. Hopefully you've found it valuable, and if so, please consider a donation.</p>
        
        <div class="support-options">
            <div class="support-option">
                <h3>Support via PayPal</h3>
                <a href="https://paypal.me/ultimateratings" target="_blank" rel="noopener noreferrer" class="support-button">PayPal</a>
            </div>
            
            <div class="support-option">
                <h3>Support via Venmo</h3>
                <a href="https://venmo.com/aidanhall" target="_blank" rel="noopener noreferrer" class="support-button">Venmo</a>
            </div>
        </div>
        
        <h2>What Your Support Enables</h2>
        <ul>
            <li>An ad-free and paywall-free experience</li>
            <li>Server and compute upgrades to keep the site fast and reliable</li>
            <li>Development time for new features and improvements</li>
        </ul>
        
        <h2>Contact</h2>
        <p style="text-align: center;">Have questions or suggestions? Reach out on <a href="https://x.com/tistonionwings" target="_blank" rel="noopener noreferrer">X (Twitter)</a>.</p>
    </div>

    <?php include_once __DIR__ . '/../src/includes/footer.php'; ?>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // FAQ toggle functionality
        const faqQuestions = document.querySelectorAll('.faq-question');
        
        faqQuestions.forEach(question => {
            question.addEventListener('click', function() {
                const answer = this.nextElementSibling;
                answer.style.display = answer.style.display === 'none' ? 'block' : 'none';
            });
        });
    });
    </script>
</body>
</html> 