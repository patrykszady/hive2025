<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Connected</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            background: white;
            padding: 3rem;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 400px;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .success-icon svg {
            width: 48px;
            height: 48px;
            stroke: white;
            stroke-width: 3;
            fill: none;
        }
        h1 {
            color: #1f2937;
            font-size: 1.5rem;
            margin: 0 0 0.5rem;
        }
        p {
            color: #6b7280;
            margin: 0 0 1.5rem;
            font-size: 0.95rem;
        }
        .email {
            font-weight: 600;
            color: #4f46e5;
        }
        .closing {
            color: #9ca3af;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">
            <svg viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        <h1>Email Connected!</h1>
        <p>Successfully connected <span class="email">{{ $email }}</span></p>
        <p class="closing">This window will close automatically...</p>
    </div>

    <script>
        // Notify parent window of success
        if (window.opener) {
            window.opener.postMessage({
                type: 'nylas-auth-success',
                email: '{{ $email }}'
            }, '*');
            
            // Close after a brief delay to show success message
            setTimeout(() => {
                window.close();
            }, 1500);
        }
    </script>
</body>
</html>
