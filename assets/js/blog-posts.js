/**
 * ReSoK blog posts - the single source for both the listing and the article page.
 *
 * Every post is read on this site. Cards used to link straight out to LinkedIn, which sent
 * readers away and left the site with nothing; where a post first appeared elsewhere the
 * article now carries a source link at the bottom instead, so attribution survives without
 * the traffic leaving.
 *
 * Fields:
 *   id          - the URL, read at /post?id=<id>
 *   body        - paragraphs, in order; plain text, escaped when rendered
 *   source      - where it was first published, shown as attribution under the article
 *   author      - optional; shown in the byline when present
 *
 * `date` is ISO and drives ordering; `displayDate` is what readers see.
 *
 * This file is the interim home for post content. Once schema-blog.sql is imported and the
 * admin editor exists, these move into blog_articles and this file goes away - the shape
 * here deliberately mirrors those columns to make that a straight migration.
 */
window.RESOK_BLOG_POSTS = [
  {
    "id": "chp-training-kiambu",
    "title": "CHP Training Program",
    "category": "Training",
    "date": "2026-04-22",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B5.jpg",
    "excerpt": "A 10-day CHP training programme is currently ongoing across Kiambu County. The eight modules—covering leadership, governance, health services, community engagement, surveillance, and commodities—are designed to broaden the capacity of CHPs to deliver quality, community-level care",
    "body": [
      "A 10-day CHP training programme is currently ongoing across Kiambu County. The eight modules—covering leadership, governance, health services, community engagement, surveillance, and commodities—are designed to broaden the capacity of CHPs to deliver quality, community-level care.",
      "This training is expected to enable CHPs to better support households, promote preventive and promotive health behaviors, and ensure timely referrals for those in need. Strengthening these skills translates into direct benefits for the sub-counties, including reliable household-level data, improved disease monitoring, empowered communities, and a more coordinated health system."
    ],
    "source": "https://www.linkedin.com/posts/respiratory-society-of-kenya-671208303_a-10-day-chp-training-programme-is-currently-activity-7396175359047573504-swbN?utm_source=share&utm_medium=member_desktop&rcm=ACoAAE1zgbUBqFRbiZ5lTERtV6KRmJtPkShJW_M",
    "sourceName": "LinkedIn"
  },
  {
    "id": "vats-camp-metrh",
    "title": "Bronchoscopy and VATS Camp at MeTRH",
    "category": "Training",
    "date": "2026-04-18",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B6.jpg",
    "excerpt": "Over the two days, our dedicated team performed multiple bronchoscopic and thoracoscopic procedures...",
    "body": [
      "Over the two days, our dedicated team performed multiple bronchoscopic and thoracoscopic procedures..."
    ]
  },
  {
    "id": "ebus-bronchoscopy-workshop",
    "title": "EBUS & Basic Bronchoscopy Workshop",
    "category": "Training",
    "date": "2026-04-12",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B7.jpg",
    "excerpt": "...participants have immersed themselves in hands-on training, expert-led lectures, and live demonstrations — all aimed at improving early diagnosis and safe management of lung diseases such as cancer, TB, and sarcoidosis...",
    "body": [
      "...participants have immersed themselves in hands-on training, expert-led lectures, and live demonstrations — all aimed at improving early diagnosis and safe management of lung diseases such as cancer, TB, and sarcoidosis..."
    ]
  },
  {
    "id": "world-lung-cancer-day",
    "title": "World Lung Cancer Day",
    "category": "Advocacy",
    "date": "2026-04-08",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B8.jpg",
    "excerpt": "🌍 Reflecting on World Lung Cancer Day – Highlights from the Lung Cancer Symposium",
    "body": [
      "🌍 Reflecting on World Lung Cancer Day – Highlights from the Lung Cancer Symposium",
      "In commemoration of World Lung Cancer Day, we hosted the Lung Cancer Symposium, bringing together experts from across Sub-Saharan Africa to address the realities of lung cancer care and chart a path forward.",
      "Key Highlights:",
      "1️⃣ Lung Cancer Landscape in Sub-Saharan Africa",
      "We explored country-specific insights from Kenya, Nigeria, and Ghana, discussing current trends, key challenges, and opportunities to improve awareness, early detection, and patient outcomes.",
      "2️⃣ Role of Navigators in Lung Cancer Care",
      "Under the theme \"Guiding the Journey: Holistic, Timely, Patient-Centered Care\", we examined how patient navigators play a vital role in bridging gaps in communication, coordinating care, and ensuring equitable access to services.",
      "3️⃣ Diagnostics – \"Which Strategy for Whom?\"",
      "A multi-disciplinary session featured:",
      "Pulmonologists (IP): Using bronchoscopy and other tools for tissue sampling.",
      "Radiologists/IR: Leveraging imaging for detection, staging, and guided biopsy.",
      "Pathologists: Providing definitive diagnosis and biomarker testing for targeted treatment planning.",
      "4️⃣ Treatment Strategies – \"Which Strategy for Whom?\"",
      "Oncologists shared updates on tailoring treatment to patient needs, highlighting the role of surgery, radiotherapy, chemotherapy, targeted therapy, and immunotherapy — and how access varies across our region.",
      "We extend our heartfelt thanks to everyone who participated and contributed to this important conversation. A special appreciation goes to our partner AstraZeneca for their unwavering support in making this symposium a success. Your collaboration and commitment to advancing lung cancer care made a real difference.",
      "AstraZeneca | Jacqueline Wanjiku Kagima MD, PhD | Njoki Njiraini | Joan Kagema | Dr MUTIE JOSEPH MUTUKU | Grace A. Humwa | Munyu Peter Waweru | Kevin Ombati | Abeid M. Athman Omar |",
      "#WorldLungCancerDy #LungCancerAwareness #Oncology #HealthcareEquity #CancerCare #GlobalHealth #LungHealth #MedicalSymposium"
    ],
    "source": "https://www.linkedin.com/posts/respiratory-society-of-kenya-671208303_worldlungcancerdy-lungcancerawareness-oncology-activity-7357704810487271424-rpa_?utm_source=share&utm_medium=member_desktop&rcm=ACoAAE1zgbUBqFRbiZ5lTERtV6KRmJtPkShJW_M",
    "sourceName": "LinkedIn"
  },
  {
    "id": "screening-outreach-thika",
    "title": "Screening Outreach",
    "category": "Outreach",
    "date": "2026-04-02",
    "displayDate": "APR, 2026",
    "image": "assets/img/pages/blog/B9.jpg",
    "excerpt": "❓When was the last time you had your chest checked?",
    "body": [
      "❓When was the last time you had your chest checked?",
      "❓Could you be living with TB and not even know it?",
      "❓Do you or your loved ones know the signs of high blood pressure or diabetes?",
      "❓What's stopping you from getting screened today — especially when it's FREE?",
      "🫁 Today is Day 1 of our 2-day health outreach, and we're bringing crucial services directly to YOU and your community! 💚",
      "Here's what we're offering FREE of charge:",
      "🧠 TB Awareness & Screening",
      "🩺 BP Check-up",
      "🩸 Diabetes Screening (RBS)",
      "🍎 Nutritional Assessment",
      "🖼️ Free Chest X-ray",
      "🧬 Free GeneXpert Test",
      "📝 SHA Registration",
      "🎯 This could be the check-up that changes your life — or saves it.",
      "💬 Don't wait until it's too late. Your health is your power.",
      "📍Come see us — Today we're here in THIKA Subcounty - Kiandutu chuma shopping Center and Tomorrow will be in Kiganjo-Kamenu kwa vibanda",
      "#TakeChargeOfYourHealth #TBscreening #CommunityCare #HealthAwareness #ActNow #PublicHealth #FreeCheckup #LungHealth #EmotionalHealthMatters"
    ],
    "source": "https://www.linkedin.com/posts/respiratory-society-of-kenya-671208303_takechargeofyourhealth-tbscreening-communitycare-activity-7354103758487764993-IM0i?utm_source=share&utm_medium=member_desktop&rcm=ACoAAE1zgbUBqFRbiZ5lTERtV6KRmJtPkShJW_M",
    "sourceName": "LinkedIn"
  },
  {
    "id": "asthma-management-webinar",
    "title": "Updates In Asthma Management",
    "category": "CME & Webinars",
    "date": "2026-03-15",
    "displayDate": "MARCH, 2026",
    "image": "assets/img/pages/blog/B2.jpg",
    "excerpt": "We invite you to be part of an important conversation aimed at improving Asthma care and outcomes. Join our webinar on “Updates in Asthma Management” today at 7:00 PM.",
    "body": [
      "We invite you to be part of an important conversation aimed at improving Asthma care and outcomes. Join our webinar on “Updates in Asthma Management” today at 7:00 PM."
    ]
  },
  {
    "id": "union-medal-chakaya",
    "title": "The Union Medal Winner",
    "category": "Recognition",
    "date": "2026-02-10",
    "displayDate": "FEB, 2026",
    "image": "assets/img/pages/blog/B4.jpg",
    "excerpt": "Congratulations to Dr Jeremiah Chakaya Muhwa, recipient of the Union Medal at #WCLH2025!...",
    "body": [
      "Congratulations to Dr Jeremiah Chakaya Muhwa, recipient of the Union Medal at #WCLH2025!..."
    ]
  },
  {
    "id": "lung-health-awareness-month",
    "title": "Lung Cancer, COPD and Pulmonary Hypertension Awareness Month",
    "category": "Advocacy",
    "date": "2025-11-05",
    "displayDate": "Nov 2025",
    "image": "assets/img/pages/blog/B6.jpg",
    "excerpt": "This November — recognized globally as Lung Cancer, COPD, and Pulmonary Hypertension (PH) Awareness Month — we stand united in raising awareness, offering compassion, and reaffirming our commitment to better lung health for all. 🫁",
    "body": [
      "This November — recognized globally as Lung Cancer, COPD, and Pulmonary Hypertension (PH) Awareness Month — we stand united in raising awareness, offering compassion, and reaffirming our commitment to better lung health for all. 🫁",
      "In this spirit, we successfully concluded a two-day free Bronchoscopy and VATS (Video-Assisted Thoracoscopic Surgery) Camp at Meru Teaching and Referral Hospital — a vital initiative aimed at bringing specialized lung care closer to the people who need it most.",
      "Over the two days, our dedicated team performed multiple bronchoscopic and thoracoscopic procedures, helping patients receive timely and accurate diagnoses for lung cancer, COPD, tuberculosis, chronic infections, pulmonary hypertension, and airway disorders. For many, this was their first time accessing such specialized care — a moment filled with both relief and hope.",
      "Behind every procedure is a story: of a patient who has struggled to breathe for months, a family searching for answers, and a community that believes in the power of compassion through care. These stories remind us why we do what we do — to ensure that no one suffers in silence due to late diagnosis or lack of access.",
      "🔍 Why Bronchoscopy Matters",
      "Unlike imaging tests such as X-rays or CT scans, bronchoscopy allows specialists to directly view the airways and collect tissue samples from deep within the lungs. This enables early and accurate diagnosis of conditions that imaging alone cannot confirm — a critical step in saving lives, especially for lung cancer and TB.",
      "🔬 About VATS (Video-Assisted Thoracoscopic Surgery)",
      "VATS is a minimally invasive surgical procedure that uses small incisions and a tiny camera to operate inside the chest cavity. It allows surgeons to diagnose or treat lung diseases with less pain, faster recovery, and fewer complications compared to open surgery — giving patients a chance at healing with dignity and comfort.",
      "This camp was more than a medical exercise — it was an act of empathy and solidarity. It reflected our belief that access to quality lung health services is not a privilege, but a right. Every patient deserves the chance to breathe freely, regardless of location or income.",
      "After successful camps in Mombasa, Kakamega, and now Meru County, we remain committed to reaching even more counties across Kenya. Our mission continues — to ensure specialized lung care reaches every corner of the country, because every breath matters.",
      "👏 We extend heartfelt appreciation to our healthcare professionals, county leadership, and partners who made this possible. Together, we are changing lives — one patient, one county, and one breath at a time.",
      "AstraZeneca | Meru Teaching and Referral Hospital | Jacqueline Wanjiku Kagima MD, PhD | Andrew Owuor | Joan Kagema | Kevin Kiptoo | @Dr.Sam Mugane | Grace A. Humwa |"
    ],
    "source": "https://www.linkedin.com/posts/respiratory-society-of-kenya-671208303_this-november-recognized-globally-as-lung-activity-7391393718194434050-igEZ?utm_source=share&utm_medium=member_desktop&rcm=ACoAAE1zgbUBqFRbiZ5lTERtV6KRmJtPkShJW_M",
    "sourceName": "LinkedIn"
  },
  {
    "id": "advanced-thoracic-ultrasound",
    "title": "Advanced Thoracic Ultrasound Workshop in Nairobi",
    "category": "Training",
    "date": "2025-11-01",
    "displayDate": "2025",
    "image": "assets/img/Gallery/B3.jpg",
    "excerpt": "Online theory, supervised hands-on training, interventional ultrasound, and certification.",
    "body": [
      "Join a comprehensive training program led by a certified expert in thoracic ultrasound. The workshop is designed to help clinicians gain hands-on experience in diagnostic and interventional techniques that elevate clinical practice.",
      "Step 1: Theory (Online - 8 Weeks)Live sessions twice a week.Coverage of physics, anatomy, pathology, and image interpretation.Free lifetime access to the full thoracic ultrasound textbook.",
      "Step 2: Hands-On Training (Nairobi)Supervised volunteer scans.Expert demonstrations.Case portfolio submission.",
      "Step 3: Interventional UltrasoundParticipants learn ultrasound-guided procedures as part of the advanced practical component.",
      "Step 4: CertificationParticipants can earn recognition as certified thoracic ultrasound physicians.",
      "Fees (Step 1 - Online Theory)Mar 1-Apr 30: $425.May 1-Jul 14: $475.Jul 15-Jul 31: $502, including a $27 late fee.",
      "Hospital Group OfferHospitals registering five or more people from the same institution receive $50 off per person, based on registration date. Offer valid until July 15, 2025.",
      "InquiriesEmail: lung.art622@gmail.comWhatsApp: +201102069714PayPal: Angel_rizk@hotmail.com"
    ],
    "subtitle": "Online theory, supervised hands-on training, interventional ultrasound, and certification.",
    "author": "Programs Team"
  },
  {
    "id": "pleuroscopy-camp",
    "title": "Advancing Lung Health: Highlights from Our Two-Day Pleuroscopy Camp",
    "category": "Training",
    "date": "2025-10-01",
    "displayDate": "2025",
    "image": "assets/img/Gallery/B5.jpg",
    "excerpt": "A successful camp supporting better diagnosis and care for patients with pleural disease.",
    "body": [
      "The good physician treats the disease; the great physician treats the patient who has the disease. - William Osler",
      "ReSoK is proud to share highlights from a successful Two-Day Pleuroscopy Camp, an important step forward in the mission to advance lung health and respiratory care.",
      "The camp brought together a team of skilled specialists dedicated to providing life-changing interventions for patients with pleural diseases. Through minimally invasive pleuroscopy procedures, the team improved diagnostic accuracy and provided timely treatment for conditions such as unexplained pleural effusions and malignancies.",
      "Over the two days, expert teams worked with dedication to ensure each patient received personalized, high-quality care. Beyond the procedures themselves, the camp represented a broader commitment to early detection, better patient outcomes, and stronger healthcare capacity in the fight against respiratory diseases.",
      "ReSoK extends deep gratitude to the dedicated medical professionals, support teams, and patients who entrusted the team with their care.",
      "AstraZeneca, Kenyatta National Hospital, Respiratory Society of Kenya, Johns Hopkins Hospital, Jacqueline Wanjiku Kagima MD, PhD, Christine Argento, Victoria Gonzalez, Andrew Owuor, Joan Kagema, Kevin Kiptoo, Joseph Mutie, and other collaborators helped make this initiative a success.",
      "Together, we are building a healthier future, one breath at a time."
    ],
    "subtitle": "A successful camp supporting better diagnosis and care for patients with pleural disease.",
    "author": "ReSoK Programs"
  },
  {
    "id": "light-writing-workshop",
    "title": "Advancing TB Research Capacity in Kenya: LIGHT Partners Spearhead Scientific Writing Workshop",
    "category": "Research",
    "date": "2025-07-01",
    "displayDate": "Jul 2025",
    "image": "assets/img/Gallery/B6.jpg",
    "excerpt": "ReSoK and AFIDEP facilitated a scientific manuscript writing workshop in Naivasha.",
    "body": [
      "One of the core outputs of the LIGHT consortium is capacity strengthening for individuals, institutions, and multi-stakeholder networks to produce, adapt, translate, and use evidence, and to manage research.",
      "In line with this, LIGHT partners in Kenya, AFIDEP and the Respiratory Society of Kenya (ReSoK), facilitated a scientific manuscript writing workshop in Naivasha, Kenya from 14-18 July 2025.",
      "Hosted by ReSoK, the workshop included participants from the Ministry of Health's Division of National Tuberculosis, Leprosy and Lung Disease Program, county government representatives, and key lung health partners including Amref Health Africa, Centre for Health Solutions - Kenya, Clinton Health Access Initiative, Kenya Medical Research Institute, and Population Services Kenya.",
      "The workshop was officially opened by Aiban Ronoh, Head of Monitoring and Evaluation and Research at NTLD-P, on behalf of the Head of NTLD-P program. He highlighted research as an important focus area and shared NTLD-P's vision of developing a national, inclusive, and demand-driven tuberculosis research agenda.",
      "Intensified research and innovation is the third pillar of the END TB Strategy. LIGHT supports documentation of research and bridges evidence-policy gaps through evidence-informed decision making.",
      "As part of streamlining TB and lung health research implementation and dissemination in Kenya, NTLD-P spearheaded the formation of a TB and Lung Health research taskforce in January 2025, where LIGHT partners ReSoK and AFIDEP are committee members.",
      "Prof. Jeremiah Chakaya, ReSoK's Chief Executive Officer and Programme Team Lead at LIGHT Consortium, gave an overview of LIGHT's research focus on gender and tuberculosis. He emphasized that age- and gender-responsive TB prevention and care policies are needed to accelerate progress toward ending TB.",
      "Dr Beate Ringwald from the LIGHT Consortium virtually conducted a sensitisation session on publishing qualitative research findings. The interactive session covered reflexivity, trustworthiness of research data, common mistakes in documentation, and practical publishing questions.",
      "The LIGHT Consortium aims to contribute to real-world change and leave no one affected by TB in sub-Saharan Africa behind."
    ],
    "subtitle": "ReSoK and AFIDEP facilitated a scientific manuscript writing workshop in Naivasha.",
    "author": "LIGHT Consortium"
  }
];
