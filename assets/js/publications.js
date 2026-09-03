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
  }
];
