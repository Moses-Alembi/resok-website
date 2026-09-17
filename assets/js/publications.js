/**
 * ReSoK publications library.
 *
 * One entry per publication. The listing on /research and the detail page at
 * /publication?id=<id> both read from here, so adding a publication is a data edit - drop
 * the PDF in assets/publications/, render a cover, add an object below. No new page.
 *
 * To add the cover thumbnail from a PDF's first page (ImageMagick + poppler):
 *   pdftoppm -jpeg -r 100 -f 1 -l 1 "assets/publications/<file>.pdf" assets/img/publications/<id>-cover
 *   magick assets/img/publications/<id>-cover-01.jpg -resize 520x -quality 82 assets/img/publications/<id>-cover.jpg
 *
 * Every field below is taken from the publication itself - nothing is paraphrased or
 * summarised, because a clinical audience will read this as the paper's own words. The
 * `overview` is the published abstract verbatim and `keyMessages` are the authors' own
 * key-messages box. If you add a publication without an abstract, write a plain summary
 * and say so, rather than inventing findings.
 */
window.RESOK_PUBLICATIONS = [
  {
    id: "cap-adults-complications",
    title: "Community-acquired pneumonia in adults: acute and long-term complications",
    type: "Review",
    journal: "The Lancet Healthy Longevity",
    year: "2026",
    doi: "10.1016/j.lanhl.2026.100872",
    openAccess: true,
    authors: [
      "Jodie Chalmers", "Krishan Bansal", "Rachel Scott", "Fergus Hamilton",
      "Rupert Payne", "Wei Shen Lim", "Grant Waterer", "Jane Shaw",
      "Jacqueline Kagima", "Anna Bibby", "Nick Maskell", "David Arnold"
    ],
    // Surfaced on the page because the Kenyan contribution is the reason this sits in a
    // ReSoK library rather than a general reading list.
    kenyaNote: "Includes Kenyan authorship - Dr Jacqueline Kagima, Department of Medicine, Kenyatta National Hospital, Nairobi.",
    file: "assets/publications/cap-adults-complications.pdf",
    fileSize: "1.9 MB",
    cover: "assets/img/publications/cap-adults-complications-cover.jpg",
    topics: ["Pneumonia", "Cardiovascular risk", "Pleural disease", "Long-term outcomes"],
    overview:
      "Annually, community-acquired pneumonia (CAP) affects millions of individuals worldwide, " +
      "resulting in more than 200 000 hospital admissions in England alone. CAP is a major cause " +
      "of morbidity and mortality. Previous studies have explored the causes, risk factors, and " +
      "the clinical course of CAP in detail. Sepsis and respiratory failure are important " +
      "well-recognised consequences of progressive infection and have been researched extensively. " +
      "In this Review, we focus on complications of CAP beyond the natural history of the condition. " +
      "These sequelae of CAP often have long-term implications for patients and encompass a wide " +
      "range of physical, functional, and psychosocial impairments, including reduced functional " +
      "ability and persistent symptoms that adversely affect quality of life. Research on " +
      "complications after CAP has increased since the COVID-19 pandemic. A growing body of " +
      "literature informs clinical trials and guides clinical management. We explore the recovery " +
      "trajectory and discuss updates in the management of post-CAP pleural infection, the " +
      "established risk of cardiovascular events after CAP, and association of CAP with cognitive " +
      "impairment and lung cancer. Finally, we conclude that improved long-term data, " +
      "standardisation, and targeted research are needed to understand, prevent, and manage these " +
      "outcomes effectively.",
    keyMessages: [
      "Pleural infection is an uncommon complication of community-acquired pneumonia (CAP) that confers high mortality. Intrapleural fibrinolytic therapy and immunomodulatory medications are being explored to improve outcomes.",
      "CAP increases the risk of cardiovascular events by activating atherosclerotic plaque inflammatory cells, cardiac remodelling, and inducing a prothrombotic state. Trials evaluating the use of antiplatelet therapy in reducing cardiovascular risk are under way.",
      "A third of the patients admitted to hospital with CAP experience functional decline in hospital; patients who are older, frailer, and have more comorbidities are more susceptible. Acute sarcopenia might also delay physical recovery in patients with CAP.",
      "More than half of the patients admitted to hospital with CAP subsequently present to primary care within 30 days of discharge. The most common reason for primary care consultation is persistent symptomatology.",
      "Moderate-to-severe cognitive impairment affects one in four people admitted to hospital with CAP. Hospitalisation for non-pneumonia infection confers a similar risk, suggesting an acute inflammatory aetiology.",
      "Rates of lung cancer diagnosis increase after CAP, even among individuals who have never smoked. Excluding patients diagnosed within a latency exclusion period to account for underlying malignancy does not eliminate this increased risk.",
      "Burden of CAP is higher in low-income and middle-income countries than in high-income countries, with increased rates of pulmonary tuberculosis. Individuals misdiagnosed with non-tuberculous pneumonia might be at increased risk of complications."
    ]
  },
  {
    id: "light-tb-evidence-brief-2026",
    title: "Leaving No-One Behind: Transforming Gendered Pathways to Health for TB (LIGHT) - Summary of Key Research Findings for Dissemination",
    type: "Evidence Brief",
    journal: "The LIGHT Consortium / National Tuberculosis, Leprosy and Lung Disease Program",
    year: "2026",
    authors: [
      "National Tuberculosis, Leprosy and Lung Disease Program (NTLD-P)",
      "African Institute for Development Policy (AFIDEP)",
      "Respiratory Society of Kenya (ReSoK)"
    ],
    file: "assets/publications/light-tb-evidence-brief-2026.pdf",
    fileSize: "11.6 MB",
    cover: "assets/img/publications/light-tb-evidence-brief-2026-cover.jpg",
    topics: ["Tuberculosis", "Stigma", "Gender", "Adolescents and young adults", "Rights-based care"],
    // Verbatim from the brief's own "Overview" section.
    overview:
      "A series of recent studies undertaken through collaborative efforts involving the " +
      "National Tuberculosis, Leprosy and Lung Disease Program, research partners, and " +
      "implementing agencies has generated important evidence to strengthen tuberculosis " +
      "prevention, diagnosis, treatment, and care in Kenya. Together, these studies provide " +
      "complementary insights on service delivery gaps, stigma, rights and gender barriers, " +
      "and public understanding of TB."
  },
  {
    id: "light-tb-impact-brief-2026",
    title: "Advancing Age and Gender Responsive Tuberculosis Prevention and Care in Kenya: LIGHT Consortium Impact",
    type: "Impact Brief",
    journal: "The LIGHT Consortium",
    year: "2026",
    authors: [
      "Liverpool School of Tropical Medicine (LSTM)",
      "African Institute for Development Policy (AFIDEP)",
      "Respiratory Society of Kenya (ReSoK)"
    ],
    file: "assets/publications/light-tb-impact-brief-2026.pdf",
    fileSize: "1.5 MB",
    cover: "assets/img/publications/light-tb-impact-brief-2026-cover.jpg",
    topics: ["Tuberculosis", "Gender", "Adolescents and young adults", "Men's health", "Policy"],
    // Verbatim from the brief's opening "Tuberculosis in Kenya" section - it has no separate
    // abstract, so this is the closest passage to one.
    overview:
      "Kenya is among the World Health Organization 30 high burden tuberculosis (TB) countries. " +
      "While significant progress has been achieved with a reduction of 45% and 58% in incidence " +
      "and mortality respectively in 2024 compared to the 2015 baseline, this is still below the " +
      "END TB strategy targets. In 2024, Kenya notified a total of 97,246 persons with TB " +
      "achieving a TB treatment coverage of 81% (WHO Global TB report, 2025).",
    // The brief's own pull-quote boxes, not a synthesis of the whole document.
    keyMessages: [
      "It is critical to disaggregate TB data by age and sex - helps to unearth population groups that are being left behind.",
      "It is important to understand what is driving gaps in the TB care cascade (the role of qualitative studies).",
      "Adolescents and Young Adults are a critical population group that must be empowered to own their health and advocate for youth responsive care.",
      "Tuberculosis services at facility level can be differentiated, patient-centred, age-and-gender responsive with a huge impact on TB notification.",
      "TB stigma can be reduced and TB appropriate health seeking behavior enhanced using men-centric approaches.",
      "Ending TB requires engaging all service providers, addressing social issues, and multisectoral engagement."
    ]
  },
  {
    id: "tb-ppm-case-detection-2026",
    title: "Enhancing TB case detection: a case study of Kenya's Global Fund–supported public–private mix",
    type: "Original Article",
    journal: "Public Health Action",
    year: "2026",
    doi: "10.5588/pha.26.0002",
    openAccess: true,
    authors: [
      "R. Pola", "L.N. Mugambi-Nyaboga", "N. Mwirigi", "A. Otieno", "I. Kathure", "N. Mukiri",
      "S. Kipkelwon", "A. Maina", "P. Warugongo", "C. Okoth", "C. Mwamsidu", "T. Kiptai",
      "A. Munene", "J. Mungai", "J. Chakaya", "E. Wandwalo", "M.A Yassin", "B. Ulo"
    ],
    // Led from ReSoK's own Public Health and Research Unit, not just contributed to.
    kenyaNote: "Led by the Public Health and Research Unit, Respiratory Society of Kenya, which oversaw implementation of the PPM intervention across the nine study counties.",
    file: "assets/publications/tb-ppm-case-detection-2026.pdf",
    fileSize: "1.1 MB",
    cover: "assets/img/publications/tb-ppm-case-detection-2026-cover.jpg",
    topics: ["Tuberculosis", "Public-private mix", "Case finding", "Private sector", "Kenya"],
    // Verbatim, the paper's own structured abstract (Background/Objective/Design/Results/Conclusion).
    overview:
      "BACKGROUND: Kenya, a high-TB-burden country, is among eight WHO-priority countries for " +
      "public–private mix (PPM) initiatives to engage all health care providers in TB prevention " +
      "and care. OBJECTIVE: To describe Kenya's experience implementing a Global Fund–supported " +
      "PPM intervention and its contribution to TB case finding. DESIGN: A descriptive case study " +
      "using programmatic data from a Global Fund–supported PPM project implemented in nine " +
      "counties in Kenya. RESULTS: Of 2,027 mapped facilities, 1,405 signed Memoranda of " +
      "Understanding and 1,269 reported TB services. Of 4.3 million people screened, 260,922 (6%) " +
      "were identified as presumptive TB, of whom 108,723 (42%) were investigated. Overall, 14,026 " +
      "individuals were diagnosed with TB (64% bacteriologically confirmed), and 99% initiated on " +
      "treatment. Level II facilities contributed 45% of notifications (7 per facility), while " +
      "Level V facilities (only 4) reported the highest average yield (130 per facility). All " +
      "counties recorded increased TB notifications during implementation, followed by a decline " +
      "in Quarter 3, 2024. CONCLUSION: Engaging private sector providers significantly enhanced TB " +
      "case detection. Kenya's PPM experience highlights the engagement choices that need to be " +
      "made among levels of the health care system for scaling and sustaining PPM models in " +
      "resource-constrained settings."
  }
];
