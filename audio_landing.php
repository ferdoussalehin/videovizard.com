<?php include 'logo_component.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StressReleasor | Choose Your Relief</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8f9ff; color: #333; margin: 0; text-align: center; }
        .hero { padding: 80px 20px; background: white; }
        .hero h1 { font-size: 48px; font-weight: 800; letter-spacing: -1px; margin-bottom: 20px; color: #1a1a2e; }
        .hero p { font-size: 20px; color: #666; max-width: 600px; margin: 0 auto; line-height: 1.5; }
        
        .grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); 
            gap: 20px; 
            max-width: 1200px; 
            margin: 40px auto; 
            padding: 20px; 
        }
        
        .card { 
            background: white; 
            padding: 35px 25px; 
            border-radius: 24px; 
            text-decoration: none; 
            color: inherit; 
            border: 2px solid transparent; 
            transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1); 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .card:hover { 
            border-color: #667eea; 
            transform: translateY(-8px); 
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.1); 
        }
        
        .icon { font-size: 45px; margin-bottom: 15px; display: block; }
        .card h3 { font-size: 20px; font-weight: 800; margin-bottom: 10px; color: #1a1a2e; }
        .card p { color: #777; font-size: 14px; line-height: 1.4; margin: 0; }
        
        @media (max-width: 600px) {
            .hero h1 { font-size: 32px; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="hero">
        <div style="margin-bottom: 30px;">
            <?php echo logo('nav', 'index.php'); ?>
        </div>
        <h1>What is weighing on you today?</h1>
        <p>Select the area causing you the most tension to begin your targeted neural reset.</p>
    </header>

    <div class="grid">
        <a href="join.php?trigger=work" class="card">
            <span class="icon">💼</span>
            <h3>Work & Career</h3>
            <p>Deadlines, high-pressure environments, and professional burnout.</p>
        </a>

        <a href="join.php?trigger=money" class="card">
            <span class="icon">💰</span>
            <h3>Money & Finance</h3>
            <p>Anxiety over bills, debt, and long-term financial security.</p>
        </a>

        <a href="join.php?trigger=relationships" class="card">
            <span class="icon">❤️</span>
            <h3>Relationships</h3>
            <p>Conflict with partners, family tension, or feeling disconnected.</p>
        </a>

        <a href="join.php?trigger=health" class="card">
            <span class="icon">🏥</span>
            <h3>Health Anxiety</h3>
            <p>Worrying about symptoms, chronic pain, or physical wellness.</p>
        </a>

        <a href="join.php?trigger=social" class="card">
            <span class="icon">👥</span>
            <h3>Social Pressure</h3>
            <p>Fear of judgment, social exhaustion, or public anxiety.</p>
        </a>

        <a href="join.php?trigger=growth" class="card">
            <span class="icon">🚀</span>
            <h3>Self-Doubt</h3>
            <p>Feeling "stuck," lack of purpose, or the inner critic.</p>
        </a>

        <a href="join.php?trigger=sleep" class="card">
            <span class="icon">🌙</span>
            <h3>Sleep & Insomnia</h3>
            <p>Racing thoughts at night and inability to switch off.</p>
        </a>

        <a href="join.php?trigger=parenting" class="card">
            <span class="icon">👪</span>
            <h3>Family & Parenting</h3>
            <p>The daily stress of raising children and household demands.</p>
        </a>
    </div>

    <footer style="padding: 60px 20px; color: #999; font-size: 14px;">
        <p>© 2026 StressReleasor | Clinically-informed neural resets.</p>
    </footer>
</body>
</html>