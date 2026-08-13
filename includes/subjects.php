<?php
// Default curated subject taxonomy -- seed data only, used exactly once to
// populate the `subjects` DB table on first run (see ensure_subjects_table()
// in functions.php). After that first migration, this file is never read
// again as a whole-table reseed; the live source of truth is the DB,
// editable from the admin subjects screen (subjects_admin.php) without
// needing a code deploy. Kept here (rather than deleted after migration) so
// a fresh install always has somewhere to seed from, and so the taxonomy is
// reviewable in git.
//
// resync_subject_keywords() (harvester.php) DOES read this file again, once,
// specifically to MERGE any new keywords added here into the live DB rows by
// slug (union, no duplicates) -- see its own comment for why a merge, not an
// overwrite. That's how an existing install picks up an expanded keyword
// list like the one below without losing admin customizations.
//
// Each subject has a 'parent' category used to group the browse sidebar
// (see get_grouped_subjects() in functions.php) — purely a display grouping,
// not a separate tag.
//
// Actual item tagging is NOT limited to this list:
//  - arXiv items are additionally tagged with the paper's own declared
//    category codes (e.g. cs.LG, astro-ph.CO, q-bio.NC — arXiv's real
//    ~155-category taxonomy), pulled straight from the API response.
//  - classify_subjects() matches keywords from every subject below against
//    every item regardless of which subject's query found it, so an item
//    can pick up several tags.
//  - Crawled items (from seed_urls) get whatever subject classify_subjects()
//    finds, with no fixed ceiling — new tags are created on the fly by
//    resolve_tag_ids() the same way manual tags always were.
//
// Keyword-list rule of thumb (learned the hard way -- see git history for
// the "gut microbiota tagged Law" incident): avoid bare common English
// words as keywords ("law", "regulation", "design" alone). classify_subjects()
// does word-boundary matching, not substring, but a generic *word* still
// shows up in unrelated writing constantly ("study design", "art of war",
// "laws of thermodynamics"). Prefer specific compound phrases instead.
//
// Confirmed on production (2026-08-13): the ORIGINAL, much narrower version
// of this list (315 keywords total, ~3.7/subject) was too conservative in
// the other direction -- classify_subjects() ran perfectly correctly (zero
// bugs, zero PCRE errors, real text every time) but genuinely found nothing
// to match on a large fraction of real, clearly-relevant content, for two
// concrete reasons: (1) zero non-English keywords at all, so French/
// Spanish/Russian/German items -- a meaningful share of the catalog given
// the international source-discovery rotation -- could never match
// regardless of topic relevance; (2) too few English synonyms/variants per
// subject. This version adds substantially more English phrasing per
// subject plus French/Spanish/German/Russian equivalents for the
// disciplines most likely to appear in non-English institutional
// repositories (history, sociology, literature, biology, physics,
// environmental science, music, communication studies, philosophy,
// economics, medicine) -- still avoiding bare single generic words per the
// rule above, just less narrowly conservative about it.

return [
    // Mathematics & Physical Sciences
    'mathematics' => ['label' => 'Mathematics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['mathematics', 'mathematical', 'topology', 'combinatorics', 'algebra', 'algebraic', 'number theory', 'differential equations', 'geometry', 'geometric', 'calculus', 'mathematical proof', 'theorem', 'mathematiques', 'matematicas', 'mathematik']],
    'physics' => ['label' => 'Physics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['physics', 'quantum', 'particle physics', 'astrophysics', 'cosmology', 'relativity', 'condensed matter', 'thermodynamics', 'electromagnetism', 'gravitational wave', 'string theory', 'physique', 'fisica', 'physik', 'физика']],
    'chemistry' => ['label' => 'Chemistry', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['chemistry', 'chemical', 'catalysis', 'organic synthesis', 'chemical reaction', 'spectroscopy', 'molecular structure', 'chimie', 'quimica', 'chemie']],
    'astronomy' => ['label' => 'Astronomy & Space', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['astronomy', 'exoplanet', 'galaxy', 'telescope', 'space mission', 'black hole', 'solar system', 'astrophysics', 'astronomie', 'astronomia']],
    'earth-science' => ['label' => 'Earth Science', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['geology', 'seismology', 'volcanology', 'geophysics', 'earth science', 'tectonic', 'mineralogy', 'geologie', 'geologia']],
    'statistics' => ['label' => 'Statistics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['statistics', 'statistical', 'statistical inference', 'bayesian', 'data analysis', 'regression model', 'probability theory', 'statistique', 'estadistica']],
    'optics' => ['label' => 'Optics & Photonics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['optics', 'photonics', 'laser physics', 'optical fiber']],
    'meteorology' => ['label' => 'Meteorology', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['meteorology', 'atmospheric science', 'weather forecasting', 'climate variability']],
    'oceanography' => ['label' => 'Oceanography', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['oceanography', 'marine geology', 'ocean circulation', 'ocean current']],
    'nuclear-physics' => ['label' => 'Nuclear Physics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['nuclear physics', 'particle accelerator', 'nuclear fission', 'nuclear fusion', 'radioactive decay']],

    // Computing & Engineering
    'artificial-intelligence' => ['label' => 'Artificial Intelligence', 'parent' => 'Computing & Engineering', 'keywords' => ['artificial intelligence', 'machine learning', 'neural network', 'deep learning', 'large language model', 'reinforcement learning', 'generative model', 'computer vision', 'natural language processing', 'intelligence artificielle', 'inteligencia artificial', 'kunstliche intelligenz']],
    'computer-science' => ['label' => 'Computer Science', 'parent' => 'Computing & Engineering', 'keywords' => ['algorithm', 'computer science', 'distributed systems', 'programming languages', 'cryptography', 'databases', 'software engineering', 'operating systems', 'compiler design', 'software development', 'informatique']],
    'robotics' => ['label' => 'Robotics', 'parent' => 'Computing & Engineering', 'keywords' => ['robotics', 'autonomous vehicle', 'robot manipulation', 'drone', 'autonomous system']],
    'engineering' => ['label' => 'Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['mechanical engineering', 'civil engineering', 'aerospace engineering', 'structural engineering', 'engineering design', 'ingenierie', 'ingenieria']],
    'materials-science' => ['label' => 'Materials Science', 'parent' => 'Computing & Engineering', 'keywords' => ['materials science', 'nanomaterial', 'polymer', 'semiconductor', 'alloy', 'nanotechnology', 'thin film', 'crystal structure']],
    'cybersecurity' => ['label' => 'Cybersecurity', 'parent' => 'Computing & Engineering', 'keywords' => ['cybersecurity', 'network security', 'malware analysis', 'penetration testing', 'data privacy', 'encryption']],
    'data-science' => ['label' => 'Data Science', 'parent' => 'Computing & Engineering', 'keywords' => ['data science', 'data mining', 'predictive modeling', 'big data']],
    'human-computer-interaction' => ['label' => 'Human-Computer Interaction', 'parent' => 'Computing & Engineering', 'keywords' => ['human-computer interaction', 'user experience design', 'usability study', 'user interface design']],
    'electrical-engineering' => ['label' => 'Electrical Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['electrical engineering', 'circuit design', 'power systems engineering', 'signal processing']],
    'chemical-engineering' => ['label' => 'Chemical Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['chemical engineering', 'process engineering', 'reaction engineering']],
    'biomedical-engineering' => ['label' => 'Biomedical Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['biomedical engineering', 'medical device design', 'tissue engineering']],
    'telecommunications' => ['label' => 'Telecommunications', 'parent' => 'Computing & Engineering', 'keywords' => ['telecommunications', 'wireless communication', '5g network', 'telecommunication network']],

    // Life Sciences & Medicine
    'biology' => ['label' => 'Biology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['biology', 'biological', 'gene', 'genome', 'cell biology', 'protein', 'evolutionary biology', 'microbiology', 'molecular biology', 'organism', 'biologie', 'biologia']],
    'medicine' => ['label' => 'Medicine & Health', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['medicine', 'clinical trial', 'disease', 'treatment', 'diagnosis', 'public health', 'epidemiology', 'patient outcome', 'healthcare', 'medical research', 'medecine', 'medicina', 'medizin', 'медицина', 'здоровье']],
    'neuroscience' => ['label' => 'Neuroscience', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['neuroscience', 'brain', 'cognitive', 'neuron', 'neural circuit', 'neuroimaging', 'neurologie']],
    'genetics' => ['label' => 'Genetics & Genomics', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['genetics', 'genomics', 'crispr', 'gene therapy', 'genetic variation', 'dna sequencing']],
    'ecology' => ['label' => 'Ecology & Environment', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['ecology', 'biodiversity', 'ecosystem', 'conservation', 'species', 'habitat', 'ecologie', 'ecologia']],
    'pharmacology' => ['label' => 'Pharmacology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['pharmacology', 'drug development', 'pharmacokinetics', 'pharmaceutical']],
    'immunology' => ['label' => 'Immunology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['immunology', 'immune response', 'vaccine development', 'autoimmune disease']],
    'toxicology' => ['label' => 'Toxicology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['toxicology', 'toxicity assessment']],
    'zoology' => ['label' => 'Zoology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['zoology', 'animal behavior', 'wildlife biology']],
    'botany' => ['label' => 'Botany', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['botany', 'plant biology', 'plant physiology']],
    'marine-biology' => ['label' => 'Marine Biology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['marine biology', 'marine ecosystem', 'coral reef']],
    'nursing' => ['label' => 'Nursing', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['nursing care', 'nursing practice', 'patient care']],
    'veterinary-science' => ['label' => 'Veterinary Science', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['veterinary medicine', 'veterinary science', 'animal health']],
    'dentistry' => ['label' => 'Dentistry', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['dentistry', 'dental medicine', 'oral health']],
    'nutrition' => ['label' => 'Nutrition', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['nutrition science', 'dietary intake', 'nutritional epidemiology']],

    // Environment & Energy
    'climate-science' => ['label' => 'Climate Science', 'parent' => 'Environment & Energy', 'keywords' => ['climate change', 'global warming', 'climate model', 'sea level', 'greenhouse gas', 'carbon emission', 'climate policy', 'changement climatique', 'cambio climatico']],
    'energy' => ['label' => 'Energy', 'parent' => 'Environment & Energy', 'keywords' => ['renewable energy', 'solar power', 'battery technology', 'nuclear energy', 'energy storage', 'power grid', 'wind energy', 'energy transition']],
    'agriculture' => ['label' => 'Agriculture & Food Science', 'parent' => 'Environment & Energy', 'keywords' => ['agriculture', 'crop science', 'food science', 'farming', 'agricultural production', 'agriculture']],
    'environmental-science' => ['label' => 'Environmental Science', 'parent' => 'Environment & Energy', 'keywords' => ['environmental science', 'pollution control', 'environmental policy', 'environmental impact', 'environnement', 'medio ambiente', 'umwelt']],
    'water-resources' => ['label' => 'Water Resources', 'parent' => 'Environment & Energy', 'keywords' => ['water resources', 'hydrology', 'water management']],
    'forestry' => ['label' => 'Forestry', 'parent' => 'Environment & Energy', 'keywords' => ['forestry', 'forest management', 'silviculture']],
    'sustainability' => ['label' => 'Sustainability', 'parent' => 'Environment & Energy', 'keywords' => ['sustainability', 'sustainable development', 'circular economy']],

    // Social Sciences
    'economics' => ['label' => 'Economics', 'parent' => 'Social Sciences', 'keywords' => ['economics', 'economic', 'econometrics', 'macroeconomic', 'trade policy', 'labor market', 'economic growth', 'economie', 'economia']],
    'finance' => ['label' => 'Finance', 'parent' => 'Social Sciences', 'keywords' => ['finance', 'asset pricing', 'financial market', 'investment strategy', 'risk management', 'corporate finance']],
    'psychology' => ['label' => 'Psychology', 'parent' => 'Social Sciences', 'keywords' => ['psychology', 'behavioral science', 'mental health', 'cognitive behavior', 'psychological wellbeing', 'psychologie', 'psicologia']],
    'sociology' => ['label' => 'Sociology', 'parent' => 'Social Sciences', 'keywords' => ['sociology', 'sociological', 'social inequality', 'social network analysis', 'demography', 'social structure', 'social movement', 'sociologie', 'sociologique', 'sociologia', 'социология']],
    'political-science' => ['label' => 'Political Science', 'parent' => 'Social Sciences', 'keywords' => ['political science', 'governance', 'public policy', 'international relations', 'political theory', 'science politique', 'ciencia politica']],
    'anthropology' => ['label' => 'Anthropology', 'parent' => 'Social Sciences', 'keywords' => ['anthropology', 'ethnography', 'cultural studies', 'anthropologie']],
    'urban-planning' => ['label' => 'Urban Planning', 'parent' => 'Social Sciences', 'keywords' => ['urban planning', 'city planning', 'transportation planning', 'housing policy']],
    'criminology' => ['label' => 'Criminology', 'parent' => 'Social Sciences', 'keywords' => ['criminology', 'crime prevention', 'criminal justice']],
    'gender-studies' => ['label' => 'Gender Studies', 'parent' => 'Social Sciences', 'keywords' => ['gender studies', 'feminist theory', 'gender equality']],
    'human-geography' => ['label' => 'Human Geography', 'parent' => 'Social Sciences', 'keywords' => ['human geography', 'spatial analysis', 'population geography']],
    'public-administration' => ['label' => 'Public Administration', 'parent' => 'Social Sciences', 'keywords' => ['public administration', 'civil service reform']],
    'international-development' => ['label' => 'International Development', 'parent' => 'Social Sciences', 'keywords' => ['international development', 'humanitarian aid', 'development economics']],

    // Humanities & Law
    // 'regulation' and bare 'law' used to be here but are too generic —
    // "iron regulation", "gene regulation", "laws of thermodynamics" etc.
    // are everyday phrasing in science writing with nothing to do with the
    // legal system, and a bare substring/word match on either false-positive
    // tagged unrelated science articles as Law. Kept to legally-specific
    // compound phrases instead.
    'law' => ['label' => 'Law', 'parent' => 'Humanities & Law', 'keywords' => ['legal', 'legislation', 'jurisprudence', 'case law', 'statute', 'regulatory compliance', 'government regulation', 'constitutional law', 'human rights law', 'droit international', 'derecho']],
    'education' => ['label' => 'Education', 'parent' => 'Humanities & Law', 'keywords' => ['education', 'pedagogy', 'learning outcomes', 'curriculum', 'teaching practice', 'educational research', 'higher education', 'education', 'educacion']],
    'dissertations-theses' => ['label' => 'Dissertations & Theses', 'parent' => 'Humanities & Law', 'keywords' => ['dissertation', 'thesis', 'theses', 'doctoral thesis', 'phd thesis']],
    'linguistics' => ['label' => 'Linguistics', 'parent' => 'Humanities & Law', 'keywords' => ['linguistics', 'natural language', 'phonetics', 'syntax', 'sociolinguistics', 'linguistique']],
    'history' => ['label' => 'History', 'parent' => 'Humanities & Law', 'keywords' => ['history', 'historical', 'archaeology', 'historiography', 'histoire', 'historia', 'geschichte', 'история']],
    'philosophy' => ['label' => 'Philosophy', 'parent' => 'Humanities & Law', 'keywords' => ['philosophy', 'ethics', 'epistemology', 'metaphysics', 'philosophical', 'philosophie', 'filosofia']],
    'religious-studies' => ['label' => 'Religious Studies', 'parent' => 'Humanities & Law', 'keywords' => ['religious studies', 'theology', 'comparative religion']],
    'literature' => ['label' => 'Literature', 'parent' => 'Humanities & Law', 'keywords' => ['literary criticism', 'literary theory', 'comparative literature', 'literary analysis', 'poetry', 'novel', 'litterature', 'literatura']],
    'classics' => ['label' => 'Classics', 'parent' => 'Humanities & Law', 'keywords' => ['classical studies', 'ancient greek literature', 'latin literature']],
    'library-science' => ['label' => 'Library & Information Science', 'parent' => 'Humanities & Law', 'keywords' => ['library science', 'information science', 'archival studies']],
    'journalism' => ['label' => 'Journalism & Media', 'parent' => 'Humanities & Law', 'keywords' => ['journalism', 'media studies', 'broadcast journalism', 'news media']],
    'communication-studies' => ['label' => 'Communication Studies', 'parent' => 'Humanities & Law', 'keywords' => ['communication studies', 'mass communication', 'public relations', 'television news', 'media coverage']],

    // Business
    'business' => ['label' => 'Business & Management', 'parent' => 'Business', 'keywords' => ['business management', 'organizational behavior', 'supply chain', 'marketing strategy', 'corporate strategy']],
    'accounting' => ['label' => 'Accounting', 'parent' => 'Business', 'keywords' => ['accounting', 'auditing', 'financial reporting']],
    'entrepreneurship' => ['label' => 'Entrepreneurship', 'parent' => 'Business', 'keywords' => ['entrepreneurship', 'startup strategy', 'venture capital']],
    'human-resources' => ['label' => 'Human Resources', 'parent' => 'Business', 'keywords' => ['human resource management', 'talent management', 'workplace culture']],
    'operations-management' => ['label' => 'Operations Management', 'parent' => 'Business', 'keywords' => ['operations management', 'logistics management', 'supply chain optimization']],
    'real-estate' => ['label' => 'Real Estate', 'parent' => 'Business', 'keywords' => ['real estate investment', 'property management']],

    // Arts & Design
    'visual-arts' => ['label' => 'Visual Arts', 'parent' => 'Arts & Design', 'keywords' => ['visual arts', 'painting technique', 'sculpture', 'contemporary art', 'art exhibition', 'art history', 'beaux-arts', 'bellas artes']],
    'design' => ['label' => 'Design', 'parent' => 'Arts & Design', 'keywords' => ['graphic design', 'industrial design', 'interior design', 'design thinking']],
    'architecture' => ['label' => 'Architecture', 'parent' => 'Arts & Design', 'keywords' => ['architecture', 'architectural design', 'urban design', 'architecture']],
    'performing-arts' => ['label' => 'Performing Arts', 'parent' => 'Arts & Design', 'keywords' => ['performing arts', 'theatre studies', 'dance studies']],
    'musicology' => ['label' => 'Music & Musicology', 'parent' => 'Arts & Design', 'keywords' => ['musicology', 'music theory', 'ethnomusicology', 'musical composition', 'orchestral', 'choral', 'musique', 'musica']],
    'film-studies' => ['label' => 'Film Studies', 'parent' => 'Arts & Design', 'keywords' => ['film studies', 'cinema studies', 'screenwriting', 'documentary film', 'cinema']],

    // Sports & Recreation
    'sports-science' => ['label' => 'Sports Science', 'parent' => 'Sports & Recreation', 'keywords' => ['sports science', 'exercise physiology', 'sports medicine']],
    'kinesiology' => ['label' => 'Kinesiology', 'parent' => 'Sports & Recreation', 'keywords' => ['kinesiology', 'biomechanics', 'motor control']],
    'tourism' => ['label' => 'Tourism & Hospitality', 'parent' => 'Sports & Recreation', 'keywords' => ['tourism management', 'hospitality management', 'travel industry']],

    // Military & Defense
    'military-studies' => ['label' => 'Military & Defense Studies', 'parent' => 'Military & Defense', 'keywords' => ['military strategy', 'defense policy', 'national security']],

    // Fallback only -- deliberately empty keywords, so classify_subjects()
    // never matches it via the normal keyword loop. insert_item_if_new()
    // (functions.php) applies it directly whenever every other source
    // (source-provided category, seed subject, keyword match) came back
    // with nothing, so no item is ever left with zero tags. Exists here
    // only so it has a real label/slug for display and browsing.
    'general' => ['label' => 'General', 'parent' => 'General', 'keywords' => []],
];
