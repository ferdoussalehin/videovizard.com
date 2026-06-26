<?php
session_start();

// Check if user is logged in
/*
if (!isset($_SESSION['client_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
*/
// Get client info
include 'dbconnect_hdb.php';
$client_id = 1; //$_SESSION['client_id'];
//echo "i am here";
$sql = "SELECT firstname, lastname FROM hdb_clients WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stmt->bind_result($firstname, $lastname);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports - StressReleasor</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header */
        .dashboard-header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .welcome-text h1 {
            font-size: 32px;
            color: #1f2937;
            margin-bottom: 5px;
        }

        .welcome-text p {
            color: #6b7280;
            font-size: 16px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-secondary:hover {
            background: #667eea;
            color: white;
        }

        /* Progress Section */
        .progress-section {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .progress-header h3 {
            color: white;
            font-size: 18px;
        }

        .progress-percentage {
            color: white;
            font-size: 24px;
            font-weight: 700;
        }

        .progress-bar {
            width: 100%;
            height: 12px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #34d399 100%);
            border-radius: 10px;
            transition: width 1s ease;
        }

        /* Reports Grid */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .report-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            border: 3px solid transparent;
        }

        .report-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transition: transform 0.4s ease;
        }

        .report-card:hover::before {
            transform: scaleX(1);
        }

        .report-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 50px rgba(102, 126, 234, 0.3);
            border-color: #667eea;
        }

        .report-card.completed {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
        }

        .report-card.pending {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }

        .report-card.pending::after {
            content: 'Not Generated Yet';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(239, 68, 68, 0.9);
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .report-icon-large {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
            transition: transform 0.4s ease;
        }

        .report-card:hover .report-icon-large {
            transform: scale(1.15) rotate(5deg);
        }

        .report-title {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 10px;
        }

        .report-description {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .report-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fee2e2;
            color: #991b1b;
        }

        .report-cta {
            margin-top: 20px;
            padding: 12px 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .report-card:hover .report-cta {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }

        /* Stats Section */
        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .stat-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }

        /* Report Modal */
        .report-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .report-modal.active {
            opacity: 1;
            pointer-events: all;
        }

        .report-modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
        }

        .report-modal-content {
            position: relative;
            background: white;
            border-radius: 20px;
            width: 95%;
            max-width: 1000px;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
            transform: scale(0.9);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .report-modal.active .report-modal-content {
            transform: scale(1);
        }

        .report-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 25px 30px;
            border-bottom: 2px solid #e5e7eb;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px 20px 0 0;
        }

        .report-modal-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: white;
        }

        .close-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 24px;
        }

        .close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .report-container {
            overflow-y: auto;
            padding: 30px;
            flex: 1;
        }

        .report-container::-webkit-scrollbar {
            width: 10px;
        }

        .report-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .report-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
        }

        /* Loading */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-spinner {
            text-align: center;
            color: white;
        }

        .spinner {
            width: 70px;
            height: 70px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-top: 5px solid white;
            border-radius: 50%;
            margin: 0 auto 25px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-spinner p {
            font-size: 18px;
            font-weight: 600;
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 30px;
            right: 30px;
            padding: 18px 28px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            z-index: 10001;
            transform: translateX(500px);
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification-error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .notification-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .dashboard-header {
                padding: 20px;
            }

            .welcome-text h1 {
                font-size: 24px;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .reports-grid {
                grid-template-columns: 1fr;
            }

            .report-modal-content {
                width: 100%;
                max-width: 100%;
                height: 100%;
                max-height: 100%;
                border-radius: 0;
            }

            .report-modal-header {
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-top">
                <div class="welcome-text">
                    <h1>👋 Welcome back, <?php echo htmlspecialchars($firstname); ?>!</h1>
                    <p>Here are your personalized assessment reports</p>
                </div>
                <div class="header-actions">
                    <a href="home.php" class="btn btn-secondary">
                        <span>🏠</span> Dashboard
                    </a>
                    <a href="chat_interface.php" class="btn btn-primary">
                        <span>💬</span> Continue Session
                    </a>
                </div>
            </div>

            <!-- Progress Section -->
            <div class="progress-section">
                <div class="progress-header">
                    <h3>📊 Assessment Progress</h3>
                    <span class="progress-percentage" id="progressPercentage">0%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill" style="width: 0%"></div>
                </div>
            </div>
        </div>

        <!-- Stats Section -->
        <div class="stats-section">
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value" id="completedCount">0</div>
                <div class="stat-label">Reports Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div class="stat-value" id="pendingCount">4</div>
                <div class="stat-label">Reports Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎯</div>
                <div class="stat-value" id="insightsCount">0</div>
                <div class="stat-label">Insights Unlocked</div>
            </div>
        </div>

        <!-- Reports Grid -->
        <div class="reports-grid" id="reportsGrid">
            <!-- Report 1: Stress Impact -->
            <div class="report-card pending" data-report="stress" onclick="viewReport('stress')">
                <span class="report-icon-large">📈</span>
                <h3 class="report-title">Stress Impact Analysis</h3>
                <p class="report-description">
                    Comprehensive breakdown of your stress levels across 24 life dimensions. Identifies your highest-impact stress areas.
                </p>
                <span class="report-status status-pending">⏳ Pending</span>
                <div class="report-cta">View Report</div>
            </div>

            <!-- Report 2: Inner Strength -->
            <div class="report-card pending" data-report="archetype" onclick="viewReport('archetype')">
                <span class="report-icon-large">💎</span>
                <h3 class="report-title">Inner Strength Profile</h3>
                <p class="report-description">
                    Discover your personality archetype and hidden psychological superpowers. Reveals your unique strengths.
                </p>
                <span class="report-status status-pending">⏳ Pending</span>
                <div class="report-cta">View Report</div>
            </div>

            <!-- Report 3: Issue Mastery -->
            <div class="report-card pending" data-report="issue" onclick="viewReport('issue')">
                <span class="report-icon-large">🎯</span>
                <h3 class="report-title">Issue Mastery Report</h3>
                <p class="report-description">
                    Your personalized strategy for overcoming challenges. Proves you already have the tools to succeed.
                </p>
                <span class="report-status status-pending">⏳ Pending</span>
                <div class="report-cta">View Report</div>
            </div>

            <!-- Report 4: Subconscious Blueprint -->
            <div class="report-card pending" data-report="philosophy" onclick="viewReport('philosophy')">
                <span class="report-icon-large">🧠</span>
                <h3 class="report-title">Subconscious Blueprint</h3>
                <p class="report-description">
                    Reveals how your mind is programmed to handle life's challenges. Shows empowering vs. limiting beliefs.
                </p>
                <span class="report-status status-pending">⏳ Pending</span>
                <div class="report-cta">View Report</div>
            </div>
        </div>
    </div>

    <script>
        // Load reports data on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadReportsDashboard();
        });

     function loadReportsDashboard() {
    console.log('📊 Loading reports dashboard...');
    
    fetch('get_available_reports.php')
        .then(response => response.json())
        .then(data => {
            console.log('Data received:', data);
            
            if (!data.authenticated) {
                console.log('❌ Not authenticated, redirecting...');
                window.location.href = 'login.php';
                return;
            }
            
            console.log('✅ Authenticated, calling updateDashboard()...');
            updateDashboard(data.reports);
        })
        .catch(error => {
            console.error('❌ Error loading dashboard:', error);
            showNotification('Failed to load reports data', 'error');
        });
}

        function updateDashboard(reports) {
            const completedReports = reports.length;
            const totalReports = 4;
            const percentage = Math.round((completedReports / totalReports) * 100);
            
            // Update progress
            document.getElementById('progressPercentage').textContent = percentage + '%';
            document.getElementById('progressFill').style.width = percentage + '%';
            
            // Update stats
            document.getElementById('completedCount').textContent = completedReports;
            document.getElementById('pendingCount').textContent = totalReports - completedReports;
            document.getElementById('insightsCount').textContent = completedReports * 8; // Rough estimate
            
            // Update report cards
            reports.forEach(report => {
                const card = document.querySelector(`[data-report="${report.id}"]`);
                if (card) {
                    card.classList.remove('pending');
                    card.classList.add('completed');
                    
                    const statusBadge = card.querySelector('.report-status');
                    statusBadge.className = 'report-status status-completed';
                    statusBadge.innerHTML = '✅ Completed';
                }
            });
        }

        function viewReport(reportType) {
            const card = document.querySelector(`[data-report="${reportType}"]`);
            if (card.classList.contains('pending')) {
                showNotification('This report has not been generated yet. Complete your assessment first.', 'error');
                return;
            }
            
            showLoadingOverlay();
            
            fetch(`get_client_reports.php?report=${reportType}`)
                .then(response => response.json())
                .then(data => {
                    hideLoadingOverlay();
                    
                    if (data.error) {
                        showNotification(data.message || data.error, 'error');
                        return;
                    }
                    
                    displayReportModal(data.report, reportType);
                })
                .catch(error => {
                    hideLoadingOverlay();
                    console.error('Error loading report:', error);
                    showNotification('Failed to load report. Please try again.', 'error');
                });
        }

        function displayReportModal(html, reportType) {
            const existingModal = document.querySelector('.report-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            const reportTitles = {
                'stress': '📈 Stress Impact Analysis',
                'archetype': '💎 Inner Strength Profile',
                'issue': '🎯 Issue Mastery Report',
                'philosophy': '🧠 Subconscious Blueprint'
            };
            
            const modal = document.createElement('div');
            modal.className = 'report-modal';
            modal.innerHTML = `
                <div class="report-modal-overlay" onclick="closeReportModal()"></div>
                <div class="report-modal-content">
                    <div class="report-modal-header">
                        <h2>${reportTitles[reportType] || 'Your Report'}</h2>
                        <button class="close-btn" onclick="closeReportModal()">✕</button>
                    </div>
                    <div class="report-container">
                        ${html}
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            
            requestAnimationFrame(() => {
                modal.classList.add('active');
            });
        }

        function closeReportModal() {
            const modal = document.querySelector('.report-modal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                setTimeout(() => modal.remove(), 300);
            }
        }

        function showLoadingOverlay() {
            const overlay = document.createElement('div');
            overlay.id = 'loading-overlay';
            overlay.innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading your report...</p>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        function hideLoadingOverlay() {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.remove();
            }
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => notification.classList.add('show'), 10);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 400);
            }, 3500);
        }
    </script>
</body>
</html>