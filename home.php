<?php
// 작성자: 조혜림
// 네비게이션
require_once 'header.php';
?>

<!-- 메인 제목 -->
<section class="intro">
    <h1 class="title">Weather Meets Baseball</h1>
    <p class="content">Discover how changing weather conditions relate to KBO game outcomes.
        Analyze runs, hits, and winning rates across different weather conditions and stadiums.
    </p>
</section>

<!-- 전체 홈 화면 -->
<section class="home-layout">
    <!-- 왼쪽 카드 -->
    <div class="home-left">
        <!-- 분석 카드 -->
        <div class="analysis-grid">
            <a href="analysis_group.php" class="analysis-card">
                <h2>Aggregating</h2>
                <p>Average runs and hits by temperature range, stadium, and game type.</p>
            </a>
            <a href="analysis_rollup.php" class="analysis-card">
                <h2>Rollup / Drill down</h2>
                <p>Explore scoring trends by region, stadium, and month.</p>
            </a>
            <a href="analysis_window.php" class="analysis-card">
                <h2>Windowing</h2>
                <p>Track moving average of team performance over recent games.</p>
            </a>
            <a href="analysis_rank.php" class="analysis-card">
                <h2>Ranking</h2>
                <p>Compare team performances under specific weather conditions.</p>
            </a>
        </div>

        <!-- CRUD 분석 카드 -->
        <a href="game_manage.php" class="crud-card">
            <div>
                <h2>CRUD</h2>
                <p>Create, Read, Update, and Delete game data.</p>
            </div>
            <div class="crud-buttons">
                <span class="crud-label">Game Data</span>
            </div>
        </a>
    </div>

    <!-- 오른쪽 카드 -->
    <aside class="info-card">
        <!-- 데이터 소스 -->
        <section class="data-sources">
            <h2>
                <img src="images/database.png" alt="database" class="section-icon">
                Data Sources
            </h2>

            <div class="source-row">
                <span class="source-label">KBO</span>
                <p>KBO official game data
                    <br>
                    - Schedules, results, and team records
                </p>
            </div>

            <div class="source-row">
                <span class="source-label">ASOS</span>
                <p>KMA ASOS weather data
                    <br>
                    - Temperature, precipitation, wind, and humidity
                </p>
            </div>

            <div class="source-row">
                <span class="source-label">Kaggle</span>
                <p>Historical KBO data
                    <br>
                    - Additional historical game records
                </p>
            </div>
        </section>

        <!-- 팀 정보 -->
        <section class="team-info">
            <h2>
                <img src="images/team.png" alt="team" class="section-icon">
                Team Info
            </h2>

            <p class="team-name">Team 04 핫식스</p>
            <p class="team-members">정윤아  |  조혜림  |  용연주  |  안예은  |  박수민</p>
        </section>
    </aside>
</section>

</main>
</body>
</html>
