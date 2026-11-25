# CSS 사용 안내

작성자: 조혜림

웹페이지 디자인은 `style.css`에서 공통으로 관리합니다. 페이지를 만들 때 아래 클래스를 사용해 주세요.

## 1. Home

![홈 화면 클래스 위치](images/css_home.png)
※ 위 사진은 GPT를 사용해 제작하였습니다.

| 클래스 | 용도 |
|---|---|
| `.site-header` | 상단 네비게이션 바 |
| `.logo`, `.nav-menu` | 로고 및 메뉴 |
| `.login-link`, `.login-icon` | 로그인 메뉴 및 아이콘 |
| `.intro`, `.title`, `.content` | 페이지 제목과 설명(분석 페이지에서도 공통 사용) |
| `.home-layout` | 홈 화면 좌우 레이아웃 |
| `.home-left` | 왼쪽 카드 묶음 |
| `.analysis-grid` | 분석 카드 2×2 배치 |
| `.analysis-card` | Aggregating·Rollup·Windowing·Ranking 카드 |
| `.crud-card`, `.crud-buttons`, `.crud-label` | CRUD 카드 및 Game Data 표시 |
| `.info-card` | 오른쪽 정보 카드 |
| `.data-sources`, `.source-row`, `.source-label` | 데이터 출처 영역 및 항목 |
| `.section-icon` | 섹션 제목 옆 아이콘 |
| `.team-info`, `.team-name`, `.team-members` | 팀 소개 영역 |

## 2. 분석 페이지 공통

![분석 페이지 클래스 위치](images/css_analysis.png)
※ 위 사진은 GPT를 사용해 제작하였습니다.

| 클래스 | 용도 |
|---|---|
| `.analysis-page-layout` | 필터·결과 영역을 좌우로 배치 |
| `.filter-panel`, `.result-panel` | 왼쪽 필터·오른쪽 결과 카드 |
| `.analysis-page-form` | 필터 폼 전체 |
| `.analysis-page-size-label` | 필터 항목 제목 위 간격 |
| `.analysis-page-options` | 라디오 버튼 선택 영역 |
| `.analysis-page-submit` | Analyze 버튼 |
| `.analysis-page-chart-title` | 그래프 제목 |
| `.analysis-page-chart` | 그래프 표시 영역 |
| `.analysis-page-table-wrap` | 테이블 스크롤 영역 |
| `.analysis-page-table` | 결과 테이블 |
| `.analysis-page-message` | 오류·데이터 없음 안내 |

필터/결과 영역의 기본 구조:

```html
<section class="analysis-page-layout">
    <div class="filter-panel">
        <h2>Filters</h2>
        <form class="analysis-page-form">
            <!-- 필터 -->
        </form>
    </div>
    <div class="result-panel">
        <h2>Analysis Results</h2>
        <!-- 그래프 및 결과 테이블 -->
    </div>
</section>
```

실제 구현 예시는 `analysis_window.php`를 참고해 주세요.

## 3. 작업 시 참고

- 공통 클래스명을 그대로 사용해 페이지 디자인을 맞춰 주세요.
- **추가 디자인 작업이나 스타일 수정이 필요하면 `style.css`를 절대로 직접 수정하지 말고 조혜림에게 요청해 주세요.**
- 새 UI 요소가 필요하면 원하는 형태와 사용할 페이지를 알려 주세요.
