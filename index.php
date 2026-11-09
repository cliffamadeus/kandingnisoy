<?php
require_once 'config/config.php';
require_once 'config/functions.php';

// Kung naka-login na, i-deretso sila sa ilang dashboard
if (isLoggedIn()) {
    if (isset($_SESSION['role'])) {
        switch($_SESSION['role']) {
            case 'admin': redirect('/app/admin/dashboard.php'); break;
            case 'manager': redirect('/app/manager/dashboard.php'); break;
            default: redirect('/app/user/dashboard.php'); break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kandingnisoy - Precision Livestock Farming for Goat Production</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #16a34a;
            --primary-dark: #14532d;
            --text: #0f172a;
            --text-2: #475569;
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
        }
        
        html {
            scroll-behavior: smooth;
            scroll-padding-top: 70px; 
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Roboto', sans-serif; }
        body { background: var(--bg); color: var(--text); line-height: 1.6; overflow-x: hidden; }
        a { text-decoration: none; color: inherit; }

        /* NAVBAR */
        .navbar {
            position: fixed; top: 0; left: 0; right: 0; height: 70px;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 40px; background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px); z-index: 1000;
            border-bottom: 1px solid var(--border);
        }
        .navbar-brand { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 900; color: var(--primary-dark); }
        .navbar-brand img { width: 40px; height: 40px; border-radius: 8px; }
        
        .nav-links { display: flex; gap: 24px; align-items: center; }
        .nav-links a { font-weight: 500; color: var(--text-2); transition: color 0.2s; font-size: 15px; }
        .nav-links a:hover { color: var(--primary); }
        
        .btn-login {
            padding: 8px 20px; border: 2px solid var(--primary); color: var(--primary);
            border-radius: 8px; font-weight: 700; transition: all 0.2s; font-size: 14px;
        }
        .btn-login:hover { background: var(--primary); color: #fff; }

        /* BURGER MENU ICON (Hidden by default) */
        .menu-toggle {
            display: none;
            font-size: 24px;
            color: var(--primary-dark);
            cursor: pointer;
            background: none;
            border: none;
        }

        /* HERO SECTION */
        .hero {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 100px 20px 40px;
            text-align: center;
            /* Mas hayag nga gradient aron klaro ang kanding */
            background: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.5)), 
                        url('<?php echo BASE_URL; ?>/assets/images/goat-bg.jpg');
            background-size: cover; 
            background-position: center; 
            background-attachment: fixed;
            position: relative;
        }

        /* Walay white background, gibutangan lang og kusog nga text-shadow */
        .hero-content {
            max-width: 1000px;
            z-index: 2;
        }

        .hero h1 {
            font-size: 54px; font-weight: 900; color: #ffffff;
            margin-bottom: 25px; line-height: 1.2;
            text-shadow: 2px 4px 10px rgba(0, 0, 0, 0.8); /* Kusog nga shadow para klaro sa hayag */
        }
        .hero p { 
            font-size: 20px; color: #f8fafc; margin: 0 auto 40px; max-width: 800px; 
            text-shadow: 1px 2px 5px rgba(0, 0, 0, 0.9);
            font-weight: 500;
        }
        /* Highlight ang mga importanteng pulong */
        .hero p strong { color: #4ade80; font-weight: 700; }
        
        .hero-buttons { display: flex; gap: 16px; justify-content: center; }
        
        .btn-primary {
            padding: 14px 32px; background: var(--primary); color: #fff;
            border-radius: 10px; font-weight: 700; font-size: 16px;
            box-shadow: 0 4px 15px rgba(22, 163, 74, 0.5); transition: all 0.2s;
        }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-secondary {
            padding: 14px 32px; background: rgba(255, 255, 255, 0.9); color: var(--primary-dark);
            border: 2px solid var(--border); border-radius: 10px; font-weight: 700; font-size: 16px;
            transition: all 0.2s; backdrop-filter: blur(5px);
        }
        .btn-secondary:hover { background: #ffffff; transform: translateY(-2px); }

        /* ABOUT SECTION */
        .about-section {
            padding: 80px 20px;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
        }
        .about-container { max-width: 900px; margin: 0 auto; }
        .about-container h2 {
            font-size: 32px; font-weight: 800; color: var(--primary-dark); margin-bottom: 20px; text-align: center;
        }
        .about-text {
            font-size: 16px; color: var(--text-2); margin-bottom: 20px; line-height: 1.8; text-align: justify;
        }
        .highlight-box {
            background: #f0fdf4;
            border-left: 4px solid var(--primary);
            padding: 20px;
            border-radius: 0 8px 8px 0;
            margin: 30px 0;
        }
        .highlight-box h4 { color: var(--primary-dark); margin-bottom: 10px; font-size: 18px; }
        .highlight-box p { margin-bottom: 0; font-size: 15px; color: var(--text-2); }

        /* FEATURES SECTION */
        .features { padding: 80px 20px; max-width: 1200px; margin: 0 auto; background: var(--bg); }
        .section-title { text-align: center; font-size: 32px; font-weight: 800; margin-bottom: 12px; color: var(--primary-dark); }
        .section-sub { text-align: center; color: var(--text-2); margin-bottom: 50px; font-size: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 30px; }
        .card {
            background: var(--surface); padding: 30px; border-radius: 16px;
            border: 1px solid var(--border); box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            transition: transform 0.2s;
        }
        .card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(22, 163, 74, 0.1); }
        .card-icon {
            width: 60px; height: 60px; border-radius: 12px; background: #dcfce7;
            display: flex; align-items: center; justify-content: center;
            color: var(--primary); margin-bottom: 20px; font-size: 28px;
        }
        .card h3 { font-size: 20px; margin-bottom: 10px; color: var(--text); }
        .card p { color: var(--text-2); font-size: 15px; }

        /* FOOTER */
        .footer { background: var(--primary-dark); color: #fff; padding: 40px 20px; text-align: center; }
        .footer p { opacity: 0.7; font-size: 14px; }

        /* =========================================
           RESPONSIVE DESIGN (MOBILE) - BURGER MENU
           ========================================= */
        @media (max-width: 768px) {
            .navbar { padding: 0 20px; }
            
            /* Itago ang normal nga links */
            .nav-links {
                position: absolute;
                top: 70px;
                left: 0;
                right: 0;
                background: #ffffff;
                flex-direction: column;
                padding: 20px;
                gap: 15px;
                box-shadow: 0 10px 15px rgba(0,0,0,0.1);
                border-bottom: 1px solid var(--border);
                /* Animation para sa dropdown */
                clip-path: polygon(0 0, 100% 0, 100% 0, 0 0);
                transition: clip-path 0.3s ease-in-out;
            }

            /* Kung i-click ang burger, mo-appear ang menu */
            .nav-links.active {
                clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%);
            }

            .nav-links a { font-size: 16px; width: 100%; text-align: center; }
            .btn-login { width: 100%; text-align: center; display: block; }

            /* Ipakita ang Burger Menu Icon */
            .menu-toggle { display: block; }

            /* I-adjust ang Hero text para sa mobile */
            .hero h1 { font-size: 36px; }
            .hero p { font-size: 16px; }
            .hero-buttons { flex-direction: column; width: 100%; max-width: 300px; margin: 0 auto; }
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="navbar-brand">
            <img src="<?php echo BASE_URL; ?>/assets/images/goat-logo.png" alt="Kandingnisoy Logo" onerror="this.style.display='none'">
            GOAT PRO
        </div>
        
        <!-- Normal Links (Desktop) -->
        <div class="nav-links" id="navLinks">
            <a href="#home" onclick="toggleMenu()">Home</a>
            <a href="#about" onclick="toggleMenu()">About</a>
            <a href="#features" onclick="toggleMenu()">Features</a>
            <a href="<?php echo BASE_URL; ?>/login.php" class="btn-login">Login</a>
        </div>

        <!-- Burger Icon (Mobile) -->
        <button class="menu-toggle" onclick="toggleMenu()">
            <i class="fas fa-bars"></i>
        </button>
    </nav>

    <!-- HERO SECTION -->
    <section class="hero" id="home">
        <!-- Walay white background, transparent na, text shadow ra ang gigamit -->
        <div class="hero-content">
            <h1>Precision Livestock Farming for Goat Production</h1>
            <p>Gamit ang <strong>Interpolated Weather Data</strong>, among sistema naghatag og automated weather advisories pinaagi sa <strong>SMS</strong> aron malikayan ang epekto sa <strong>Heat Stress</strong> ug mapalambo ang produksyon sa mga kanding sa Manolo Fortich, Bukidnon.</p>
            <div class="hero-buttons">
                <a href="<?php echo BASE_URL; ?>/login.php" class="btn-primary">Login</a>
                <a href="#about" class="btn-secondary">Learn More</a>
            </div>
        </div>
    </section>

    <!-- ABOUT SECTION -->
    <section id="about" class="about-section">
        <div class="about-container">
            <h2>About the System</h2>
            
            <p class="about-text">
                Ang <strong>Goat Production sa Pilipinas</strong> kay dako kaayo og tabang sa food security, labi na sa mga smallholder farms nga nag-buhi og 99.4% sa tanang kanding sa nasod. Apan, tungod sa Climate Change, nagkataas ang temperatura ug nag-usab ang pattern sa ulan. Kini nga mga kausaban nagdala og <strong>Heat Stress</strong> sa mga kanding, nga makapahinay sa ilang pagtubo, makapakunhod sa gatas, ug makapaluya sa ilang immune system.
            </p>

            <p class="about-text">
                Apan, daghan sa mga mag-uuma sa Manolo Fortich, Bukidnon ang nag-rely lang sa tradisyonal nga pamaagi sa pag-tugway ug walay access sa mga mahalon nga weather sensors o teknolohiya. Mao kini ang problema nga among gitubag pinaagi sa <strong>GoatPro (Precision Livestock Farming System)</strong>.
            </p>

            <div class="highlight-box">
                <h4>Unsaon niya pag-obra?</h4>
                <p>Imbes nga mogamit og physical sensors sa uma, among sistema mag-gamit sa <strong>Interpolated Weather Data</strong> mag-estimate sa lokal nga panahon base sa datos gikan sa mga kasikbit nga weather stations. Kini i-convert ngadto sa <strong>Temperature-Humidity Index (THI)</strong> aron mahibaw-an kung naay heat stress risk. Kung naay kakuyaw (sama sa kusog nga ulan o grabeng kainit), <strong>automatic nga magpadala og SMS Advisory</strong> ang sistema ngadto sa cellphone sa mag-uuma aron makahimo sila dayon og aksyon sama sa pagpasulod sa kanding sa kural.</p>
            </div>

            <p class="about-text" style="text-align: center; font-weight: bold; margin-top: 30px; color: var(--primary);">
                A Capstone Project by Giovanny Barsbia, Diether J A. Labis, & Silverio M. Laspoña Jr.<br>
                <span style="font-size: 14px; font-weight: normal; color: var(--text-2);">Northern Bukidnon State College</span>
            </p>
        </div>
    </section>

    <!-- FEATURES SECTION  LOCALHOST-->
    <section id="features" class="features">
        <h2 class="section-title">Features sa GOAT PRO</h2>
        <p class="section-sub">Ang mga teknolohiya nga nagsuporta sa atong mga mag-uuma.</p>
        
        <div class="grid">
            <!-- Feature 1 -->
            <div class="card">
                
                <h3>Interpolated Weather Forecast</h3>
                <p>Wala nay kinahanglan og mahalon nga weather sensor. Among sistema mag-estimate sa lokal nga panahon base sa datos sa mga kasikbit nga weather stations, ug i-convert kini ngadto sa Heat Stress Index (THI) para sa kanding.</p>
            </div>

            <!-- Feature 2 -->
            <div class="card">
                
                <h3>Automated SMS Advisories</h3>
                <p>Pag-abot sa kakuyaw (sama sa kusog nga ulan o sobrang init), automatic nga magpadala og SMS ang sistema ngadto sa mga farmer nga naka-register, aron makahimo sila og dayon nga aksyon sama sa pagpasulod sa kanding sa kural.</p>
            </div>

            <!-- Feature 3 -->
            <div class="card">
                
                <h3>Goat Inventory & Reports</h3>
                <p>I-track ang mga kanding pinaagi sa Goat ID. Makita usab sa Municipal Agriculture Office (MAO) ang mga report sa mga mag-uuma pinaagi sa pag-export niini ngadto sa PDF.</p>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer">
        <h2 style="margin-bottom: 10px;">Kandingnisoy System</h2>
        <p>&copy; <?php echo date('Y'); ?> GoatPro - Precision Livestock Farming for Goat Production. All rights reserved.</p>
        <p style="margin-top: 8px;">Northern Bukidnon State College 🐐</p>
    </footer>

    <!-- SCRIPT PARA SA BURGER MENU -->
    <script>
        function toggleMenu() {
            var nav = document.getElementById("navLinks");
            nav.classList.toggle("active");
        }
    </script>
</body>
</html>