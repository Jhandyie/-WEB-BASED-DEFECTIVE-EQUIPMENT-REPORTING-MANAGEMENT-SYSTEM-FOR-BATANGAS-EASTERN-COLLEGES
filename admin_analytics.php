<?php
// admin_analytics.php
session_start();
require_once 'includes/auth.php';
require_once 'file_storage_helpers.php';

requireRole('admin');

// Get analytics data
$reservationTrends = getReservationsOverTime(30);
$defectTrends = getDefectsOverTime(30);
$equipmentUsage = getEquipmentUsageOverTime(30);
$equipmentStatus = getEquipmentStatusDistribution();
$categoryStats = getCategoryUsageStats();
$monthlyTrends = getMonthlyTrends(12);



// Get system statistics
$stats = getInventoryStatistics();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Admin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats-card {
            transition: transform 0.2s;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .gradient-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .gradient-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .gradient-warning {
            background: linear-gradient(135deg, #fcb045 0%, #fd1d1d 100%);
        }
        .gradient-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .card-custom {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        .card-custom:hover {
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .sidebar {
            background: linear-gradient(180deg, #343a40 0%, #495057 100%);
            min-height: 100vh;
        }
        .main-content {
            background-color: #f8f9fa;
        }
        .border-left-primary {
            border-left: 4px solid #0d6efd !important;
        }
        .border-left-success {
            border-left: 4px solid #198754 !important;
        }
        .border-left-warning {
            border-left: 4px solid #ffc107 !important;
        }
        .border-left-info {
            border-left: 4px solid #0dcaf0 !important;
        }
    </style>
</head>
<body>
    <!-- Main Content -->
    <div class="main-content">
            <!-- Header -->
            <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
                <div class="container-fluid">
                    <div class="d-flex align-items-center">
                        <a href="admin_dashboard.php" class="btn btn-outline-primary me-3">
                            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                        </a>
                        <h4 class="mb-0 text-primary">
                            <i class="bi bi-graph-up me-2"></i>System Analytics
                        </h4>
                    </div>
                    <div class="d-flex">
                        <span class="badge bg-primary fs-6">Live Data</span>
                    </div>
                </div>
            </nav>

            <div class="container-fluid p-4">
                <!-- System Analytics Section -->
                <div class="row mb-5">
                    <div class="col-12">
                        <div class="d-flex align-items-center mb-4">
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <i class="bi bi-graph-up fs-4"></i>
                            </div>
                            <div class="ms-3">
                                <h2 class="text-primary mb-0">System Analytics</h2>
                                <p class="text-muted mb-0">Overview of reservations, defects, and equipment usage</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Overview Statistics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stats-card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Equipment
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_equipment']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="stat-icon bg-primary text-white">
                                            <i class="bi bi-cogs"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stats-card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Available Equipment
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['available_equipment']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="stat-icon bg-success text-white">
                                            <i class="bi bi-check-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stats-card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Defect Reports
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_reports']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="stat-icon bg-warning text-white">
                                            <i class="bi bi-exclamation-triangle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stats-card border-left-info shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Total Reservations
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_reservations']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="stat-icon bg-info text-white">
                                            <i class="bi bi-calendar-check"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Analytics Charts -->
                <div class="row mb-4">
                    <div class="col-lg-6 mb-4">
                        <div class="card card-custom shadow">
                            <div class="card-header bg-primary text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>
                                    Reservation Trends (Last 30 Days)
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="reservationChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="card card-custom shadow">
                            <div class="card-header bg-danger text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    Defect Reports (Last 30 Days)
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="defectChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-6 mb-4">
                        <div class="card card-custom shadow">
                            <div class="card-header bg-success text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-bar-chart me-2"></i>
                                    Equipment Usage (Last 30 Days)
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="usageChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="card card-custom shadow">
                            <div class="card-header bg-warning text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-pie-chart me-2"></i>
                                    Equipment Status Distribution
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-5">
                    <div class="col-12">
                        <div class="card card-custom shadow">
                            <div class="card-header bg-info text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-calendar-event me-2"></i>
                                    Monthly Trends (Last 12 Months)
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 400px;">
                                    <canvas id="monthlyChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-5">
                    <div class="col-12">
                        <div class="card card-custom shadow">
                            <div class="card-header bg-primary text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-activity me-2"></i>
                                    Activity Overview (Last 30 Days)
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 400px;">
                                    <canvas id="activityChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category Usage Stats -->
                <div class="row mb-5">
                    <div class="col-12">
                        <div class="card card-custom shadow">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-tags me-2"></i>
                                    Category Usage Statistics
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th><i class="bi bi-tag me-1"></i>Category</th>
                                                <th><i class="bi bi-boxes me-1"></i>Total Equipment</th>
                                                <th><i class="bi bi-calendar-check me-1"></i>Reservations</th>
                                                <th><i class="bi bi-percent me-1"></i>Usage Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($categoryStats as $category): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($category['name']); ?></td>
                                                <td><span class="badge bg-primary"><?php echo number_format($category['total_equipment']); ?></span></td>
                                                <td><span class="badge bg-success"><?php echo number_format($category['reservations']); ?></span></td>
                                                <td>
                                                    <?php
                                                    $rate = $category['total_equipment'] > 0 ? round(($category['reservations'] / $category['total_equipment']) * 100, 1) : 0;
                                                    $rateClass = $rate > 50 ? 'bg-danger' : ($rate > 25 ? 'bg-warning' : 'bg-success');
                                                    ?>
                                                    <span class="badge <?php echo $rateClass; ?>"><?php echo $rate; ?>%</span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


            </div>
        </div>
    </div>

    <script src="js/loading_utils.js"></script>

    <!-- Loading Overlay - Add to every page -->
    <div id="global-loading-overlay" class="loading-overlay">
        <div class="loading-spinner-container">
            <div class="loading-spinner"></div>
            <p class="loading-text">Loading...</p>
        </div>
    </div>

    <script>
        // Initialize charts when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
        });

        function initCharts() {
            try {
                // Reservation Trends Chart
                const reservationCtx = document.getElementById('reservationChart');
                if (reservationCtx) {
                    const reservationData = <?php echo json_encode($reservationTrends); ?>;
                    if (reservationData && reservationData.length > 0) {
                        new Chart(reservationCtx, {
                            type: 'line',
                            data: {
                                labels: reservationData.map(item => item.date),
                                datasets: [{
                                    label: 'Reservations',
                                    data: reservationData.map(item => item.count),
                                    borderColor: '#0d6efd',
                                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4,
                                    pointBackgroundColor: '#0d6efd',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    pointRadius: 4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: false
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        },
                                        ticks: {
                                            stepSize: 1
                                        }
                                    },
                                    x: {
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        }
                                    }
                                },
                                interaction: {
                                    intersect: false,
                                    mode: 'index'
                                }
                            }
                        });
                    }
                }

                // Defect Trends Chart
                const defectCtx = document.getElementById('defectChart');
                if (defectCtx) {
                    const defectData = <?php echo json_encode($defectTrends); ?>;
                    if (defectData && defectData.length > 0) {
                        new Chart(defectCtx, {
                            type: 'line',
                            data: {
                                labels: defectData.map(item => item.date),
                                datasets: [{
                                    label: 'Defect Reports',
                                    data: defectData.map(item => item.count),
                                    borderColor: '#dc3545',
                                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4,
                                    pointBackgroundColor: '#dc3545',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    pointRadius: 4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: false
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        },
                                        ticks: {
                                            stepSize: 1
                                        }
                                    },
                                    x: {
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        }
                                    }
                                }
                            }
                        });
                    }
                }

                // Equipment Usage Chart
                const usageCtx = document.getElementById('usageChart');
                if (usageCtx) {
                    const usageData = <?php echo json_encode($equipmentUsage); ?>;
                    if (usageData && usageData.length > 0) {
                        new Chart(usageCtx, {
                            type: 'bar',
                            data: {
                                labels: usageData.map(item => item.date),
                                datasets: [{
                                    label: 'Active Reservations',
                                    data: usageData.map(item => item.count),
                                    backgroundColor: 'rgba(25, 135, 84, 0.8)',
                                    borderColor: '#198754',
                                    borderWidth: 2,
                                    borderRadius: 4,
                                    borderSkipped: false
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: false
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        },
                                        ticks: {
                                            stepSize: 1
                                        }
                                    },
                                    x: {
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        }
                                    }
                                }
                            }
                        });
                    }
                }

                // Equipment Status Distribution Chart
                const statusCtx = document.getElementById('statusChart');
                if (statusCtx) {
                    const statusData = <?php echo json_encode($equipmentStatus); ?>;
                    if (statusData) {
                        new Chart(statusCtx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Available', 'In Use', 'Maintenance', 'Defective'],
                                datasets: [{
                                    data: [
                                        statusData.available || 0,
                                        statusData['in-use'] || 0,
                                        statusData.maintenance || 0,
                                        statusData.defective || 0
                                    ],
                                    backgroundColor: [
                                        '#198754',
                                        '#0d6efd',
                                        '#ffc107',
                                        '#dc3545'
                                    ],
                                    borderWidth: 3,
                                    borderColor: '#fff',
                                    hoverBorderWidth: 4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        labels: {
                                            padding: 20,
                                            usePointStyle: true
                                        }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                                const percentage = Math.round((context.parsed / total) * 100);
                                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }
                }

                // Monthly Trends Chart
                const monthlyCtx = document.getElementById('monthlyChart');
                if (monthlyCtx) {
                    const monthlyData = <?php echo json_encode($monthlyTrends); ?>;
                    if (monthlyData && monthlyData.length > 0) {
                        new Chart(monthlyCtx, {
                            type: 'line',
                            data: {
                                labels: monthlyData.map(item => item.month),
                                datasets: [{
                                    label: 'Reservations',
                                    data: monthlyData.map(item => item.reservations),
                                    borderColor: '#0d6efd',
                                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4,
                                    pointBackgroundColor: '#0d6efd',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    pointRadius: 5
                                }, {
                                    label: 'Defect Reports',
                                    data: monthlyData.map(item => item.defects),
                                    borderColor: '#dc3545',
                                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4,
                                    pointBackgroundColor: '#dc3545',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    pointRadius: 5
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'top',
                                        labels: {
                                            usePointStyle: true,
                                            padding: 20
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        },
                                        ticks: {
                                            stepSize: 1
                                        }
                                    },
                                    x: {
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        }
                                    }
                                },
                                interaction: {
                                    mode: 'index',
                                    intersect: false
                                }
                            }
                        });

                    }

                }

                // Activity Overview Chart
                const activityCtx = document.getElementById('activityChart');
                if (activityCtx) {
                    const reservationData = <?php echo json_encode($reservationTrends); ?>;
                    const defectData = <?php echo json_encode($defectTrends); ?>;
                    const usageData = <?php echo json_encode($equipmentUsage); ?>;
                    if (reservationData && reservationData.length > 0) {
                        new Chart(activityCtx, {
                            type: 'line',
                            data: {
                                labels: reservationData.map(item => item.date),
                                datasets: [
                                    { label: 'Reservations', data: reservationData.map(item => item.count), borderColor: '#17a2b8' },
                                    { label: 'Defect Reports', data: defectData.map(item => item.count), borderColor: '#dc3545' },
                                    { label: 'Equipment Usage', data: usageData.map(item => item.count), borderColor: '#28a745' }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'top',
                                        labels: {
                                            usePointStyle: true,
                                            padding: 20
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        },
                                        ticks: {
                                            stepSize: 1
                                        }
                                    },
                                    x: {
                                        grid: {
                                            color: 'rgba(0,0,0,0.05)'
                                        }
                                    }
                                },
                                interaction: {
                                    mode: 'index',
                                    intersect: false
                                }
                            }
                        });
                    }
                }

            } catch (error) {
                console.error('Chart initialization error:', error);
            }
        }
    </script>


</body>
</html>
