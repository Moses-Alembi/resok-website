/**
 * ReSoK blog posts.
 *
 * One entry per post; blog.html builds the whole page from this list - the lead story, the
 * secondary row, the category filter and the grid all read from here, so publishing is a
 * data edit rather than new markup.
 *
 * Each entry links one of two ways, and exactly one should be set:
 *   post     - an article on this site, read at /post?id=<value>
 *   external - a post published elsewhere (LinkedIn); the card says so, because sending a
 *              reader off-site without warning is a worse experience than admitting it.
 *
 * `date` is ISO and drives ordering; `displayDate` is what readers see, kept separate
 * because several of these are only known to the month.
 */
window.RESOK_BLOG_POSTS = [
  {
    "id": "chp-training-kiambu",
    "title": "CHP Training Program",
    "category": "Training",
    "date": "2026-04-22",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B5.jpg",
    "excerpt": "A 10-day CHP training programme is currently ongoing across Kiambu County. The eight modules—covering leadership, governance, health services, community engagement, surveillance...",
    "external": "https://www.linkedin.com/posts/respiratory-society-of-kenya-671208303_a-10-day-chp-training-programme-is-currently-activity-7396175359047573504-swbN?utm_source=share&utm_medium=member_desktop&rcm=ACoAAE1zgbUBqFRbiZ5lTERtV6KRmJtPkShJW_M"
  },
  {
    "id": "vats-camp-metrh",
    "title": "Bronchoscopy and VATS Camp at MeTRH",
    "category": "Training",
    "date": "2026-04-18",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B6.jpg",
    "excerpt": "Over the two days, our dedicated team performed multiple bronchoscopic and thoracoscopic procedures...",
    "external": "https://www.linkedin.com/posts/respiratory-society-of-kenya-671208303_this-november-recognized-globally-as-lung-activity-7391393718194434050-igEZ?utm_source=share&utm_medium=member_desktop&rcm=ACoAAE1zgbUBqFRbiZ5lTERtV6KRmJtPkShJW_M"
  },
  {
    "id": "ebus-bronchoscopy-workshop",
    "title": "EBUS & Basic Bronchoscopy Workshop",
    "category": "Training",
    "date": "2026-04-12",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B7.jpg",
    "excerpt": "...participants have immersed themselves in hands-on training, expert-led lectures, and live demonstrations — all aimed at improving early diagnosis and safe management of lung diseases such as cancer, TB, and sarcoidosis...",
    "external": "https://shorturl.at/DKKXb"
  },
  {
    "id": "world-lung-cancer-day",
    "title": "World Lung Cancer Day",
    "category": "Advocacy",
    "date": "2026-04-08",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B8.jpg",
    "excerpt": "In commemoration of World Lung Cancer Day, we hosted the Lung Cancer Symposium, bringing together experts from across Sub-Saharan Africa to address the realities of lung cancer care and chart a path forward.",
    "external": "https://www.linkedin.com/posts/respiratory-society-of-kenya-671208303_worldlungcancerdy-lungcancerawareness-oncology-activity-7357704810487271424-rpa_?utm_source=share&utm_medium=member_desktop&rcm=ACoAAE1zgbUBqFRbiZ5lTERtV6KRmJtPkShJW_M"
  },
  {
    "id": "screening-outreach-thika",
    "title": "Screening Outreach",
    "category": "Outreach",
    "date": "2026-04-02",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B9.jpg",
    "excerpt": "Come see us — Today we’re here in THIKA Subcounty - Kiandutu chuma shopping Center and Tomorrow will be in Kiganjo-Kamenu kwa vibanda",
    "external": "https://www.linkedin.com/posts/respiratory-society-of-kenya-671208303_takechargeofyourhealth-tbscreening-communitycare-activity-7354103758487764993-IM0i?utm_source=share&utm_medium=member_desktop&rcm=ACoAAE1zgbUBqFRbiZ5lTERtV6KRmJtPkShJW_M"
  },
  {
    "id": "asthma-management-webinar",
    "title": "Updates In Asthma Management",
    "category": "CME & Webinars",
    "date": "2026-03-15",
    "displayDate": "MARCH, 2026",
    "image": "assets/img/pages/blog/B2.jpg",
    "excerpt": "We invite you to be part of an important conversation aimed at improving Asthma care and outcomes. Join our webinar on “Updates in Asthma Management” today at 7:00 PM.",
    "external": "https://shorturl.at/DKKXb"
  },
  {
    "id": "union-medal-chakaya",
    "title": "The Union Medal Winner",
    "category": "Recognition",
    "date": "2026-02-10",
    "displayDate": "FEB, 2026",
    "image": "assets/img/pages/blog/B4.jpg",
    "excerpt": "Congratulations to Dr Jeremiah Chakaya Muhwa, recipient of the Union Medal at #WCLH2025!...",
    "external": "https://shorturl.at/DKKXb"
  },
  {
    "id": "advanced-thoracic-ultrasound",
    "title": "Advanced Thoracic Ultrasound Workshop in Nairobi",
    "category": "Training",
    "date": "2025-11-01",
    "displayDate": "2025",
    "image": "assets/img/Gallery/B3.jpg",
    "excerpt": "Online theory, supervised hands-on training, interventional ultrasound, and certification.",
    "post": "advanced-thoracic-ultrasound"
  },
  {
    "id": "pleuroscopy-camp",
    "title": "Advancing Lung Health: Highlights from Our Two-Day Pleuroscopy Camp",
    "category": "Training",
    "date": "2025-10-01",
    "displayDate": "2025",
    "image": "assets/img/Gallery/B5.jpg",
    "excerpt": "A successful camp supporting better diagnosis and care for patients with pleural disease.",
    "post": "pleuroscopy-camp"
  },
  {
    "id": "light-writing-workshop",
    "title": "Advancing TB Research Capacity in Kenya: LIGHT Partners Spearhead Scientific Writing Workshop",
    "category": "Research",
    "date": "2025-07-01",
    "displayDate": "Jul 2025",
    "image": "assets/img/Gallery/B6.jpg",
    "excerpt": "ReSoK and AFIDEP facilitated a scientific manuscript writing workshop in Naivasha.",
    "post": "light-writing-workshop"
  }
];
