/** Panel dataset: 4 diseases + healthy (~1k images per class). */
export const SUPPORTED_DATASET_KEYS = ['blast', 'blb', 'brown_spot', 'sheath_blight', 'tungro', 'healthy'];

export const ALL_DISEASES = {
    blast: { key: 'blast', name: 'Leaf Blast', scientific: 'Magnaporthe oryzae', severity: 'severe' },
    blb: { key: 'blb', name: 'Bacterial Leaf Blight', scientific: 'Xanthomonas oryzae pv. oryzae', severity: 'moderate' },
    brown_spot: { key: 'brown_spot', name: 'Brown Spot', scientific: 'Bipolaris oryzae', severity: 'moderate' },
    sheath_blight: { key: 'sheath_blight', name: 'Sheath Blight', scientific: 'Rhizoctonia solani', severity: 'moderate' },
    tungro: { key: 'tungro', name: 'Rice Tungro Disease', scientific: 'RTBV + RTSV viruses', severity: 'severe' },
    healthy: { key: 'healthy', name: 'Healthy', scientific: null, severity: 'healthy' },
};

export const SUPPORTED_DISEASE_LABELS = [
    'Bacterial Leaf Blight (BLB)',
    'Leaf Blast',
    'Brown Spot',
    'Sheath Blight',
    'Rice Tungro',
    'Healthy Leaf',
];

export function getUnsupportedScan(previewUrl, message, messageTl) {
    const now = new Date();
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return {
        recognized: false,
        image_url: previewUrl,
        message: message || 'This image does not match a labeled image in the current reference dataset.',
        message_tl: messageTl || 'Ang sakit o kondisyon na ito ay wala sa aming dataset.',
        supported_diseases: SUPPORTED_DISEASE_LABELS,
        date: `${months[now.getMonth()]} ${now.getDate()}, ${now.getFullYear()}`,
        time: now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
    };
}

export function getDefaultTreatments(key, severity = 'moderate') {
    if (key === 'blast') {
        if (severity === 'mild') {
            return {
                chemical: [
                    { name: 'Tricyclazole 75% WP', desc: 'Apply 0.6–1.0 g/L foliar spray at early tillering to prevent blast spore penetration into epidermal cells.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
                    { name: 'Kasugamycin 2% SL', desc: 'Apply 1.5–2.0 ml/L at first appearance of pinpoint diamond lesions. Inhibits fungal protein synthesis.', tag: 'Antibiotic Fungicide', tag_class: 'fungicide' },
                ],
                organic: [
                    { name: 'Balanced Nitrogen Application', desc: 'Halt topdress urea application; apply fertilizer in split doses to avoid leafy excessive succulent growth.', tag: 'Cultural', tag_class: 'cultural' },
                    { name: 'Silica & Potassium Amendment', desc: 'Apply rice hull ash (silica source) and Muriate of Potash (30–40 kg/ha) to thicken leaf cuticle.', tag: 'Nutritional', tag_class: 'cultural' },
                ],
            };
        } else if (severity === 'severe') {
            return {
                chemical: [
                    { name: 'Isoprothiolane 40% EC + Tricyclazole Tank Mix', desc: 'Emergency therapeutic foliar application (2.0 ml + 1.0 g/L) to arrest galloping leaf blast and protect flag leaves.', tag: 'Emergency Fungicide', tag_class: 'fungicide' },
                    { name: 'Azoxystrobin + Difenoconazole', desc: 'Apply 1.0 ml/L spray to arrest extensive lesion coalescence and prevent transition to panicle blast.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
                ],
                organic: [
                    { name: 'Total Nitrogen Halt & Deep Water Drainage', desc: 'Completely suspend all nitrogen fertilizers and practice intermittent field aeration.', tag: 'Cultural', tag_class: 'cultural' },
                    { name: 'Resistant Varieties for Next Season', desc: 'Plant blast-resistant certified inbred seeds such as NSIC Rc222, PSB Rc18, or Tubigan lines.', tag: 'Varietal Selection', tag_class: 'cultural' },
                ],
            };
        } else {
            return {
                chemical: [
                    { name: 'Isoprothiolane 40% EC (Fuji-One)', desc: 'Apply 1.5–2.0 ml/L foliar spray. Systemic fungicide with strong translaminar translocation.', tag: 'Fungicide', tag_class: 'fungicide' },
                    { name: 'Azoxystrobin + Difenoconazole', desc: 'Apply 1.0 ml/L spray. Dual systemic strobilurin + triazole providing curative and anti-sporulant action.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
                ],
                organic: [
                    { name: 'Complete Nitrogen Suspension', desc: 'Immediately halt topdressing until blast spots dry up and active sporulation ceases.', tag: 'Cultural', tag_class: 'cultural' },
                    { name: 'Potassium Boost (MOP 0-0-60)', desc: 'Apply 30–40 kg/ha K₂O to strengthen cell walls against fungal penetration.', tag: 'Nutritional', tag_class: 'cultural' },
                ],
            };
        }
    }
    if (key === 'brown_spot') {
        if (severity === 'mild') {
            return {
                chemical: [
                    { name: 'Mancozeb 80% WP (Dithane M-45)', desc: 'Apply 2.0–2.5 g/L protective contact foliar spray at early tillering.', tag: 'Fungicide', tag_class: 'fungicide' },
                    { name: 'Propiconazole 25% EC (Tilt)', desc: 'Apply 1.0 ml/L spray at first emergence of pinpoint brown specks.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
                ],
                organic: [
                    { name: 'Potassium & Zinc Soil Amendment', desc: 'Apply Muriate of Potash (30–40 kg/ha) and Zinc Sulfate (25 kg/ha) to rectify nutrient deficiencies.', tag: 'Nutritional', tag_class: 'cultural' },
                    { name: 'Organic Compost Incorporation', desc: 'Incorporate 2–3 tons/ha well-decomposed organic compost to revive soil microbial activity.', tag: 'Soil Health', tag_class: 'cultural' },
                ],
            };
        } else if (severity === 'severe') {
            return {
                chemical: [
                    { name: 'Propiconazole 25% EC + Mancozeb Tank Mix', desc: 'Therapeutic emergency spray (1.0 ml + 2.0 g/L) to prevent glume blotch and pecky rice grain damage.', tag: 'Emergency Fungicide', tag_class: 'fungicide' },
                    { name: 'Carbendazim 50% WP + Tebuconazole 250 EC', desc: 'Apply 1.0–1.5 g/L spray targeted at flag leaves and panicles.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
                ],
                organic: [
                    { name: 'Certified Resistant Seeds Next Cropping', desc: 'Plant certified seeds with high field tolerance against brown spot (NSIC Rc216, NSIC Rc222, PSB Rc14).', tag: 'Varietal Selection', tag_class: 'cultural' },
                    { name: 'Hot Water Seed Disinfection', desc: 'Soak seeds in 52–54°C warm water for 15 minutes before pre-germination to eliminate seed-borne fungal mycelia.', tag: 'Cultural', tag_class: 'cultural' },
                ],
            };
        } else {
            return {
                chemical: [
                    { name: 'Tebuconazole 250 EC (Folicur)', desc: 'Apply 1.0 ml/L foliar spray to halt mycelial growth and control brown spots.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
                    { name: 'Azoxystrobin + Difenoconazole', desc: 'Apply 1.0 ml/L spray for dual systemic curative and protective action.', tag: 'Fungicide', tag_class: 'fungicide' },
                ],
                organic: [
                    { name: 'Split Potassium Topdressing', desc: 'Apply 50% potash at basal and 50% at panicle initiation stage (15–20 kg/ha K₂O).', tag: 'Nutritional', tag_class: 'cultural' },
                    { name: 'Alternate Wetting and Drying (AWD)', desc: 'Practice AWD irrigation to aerate soil and enhance root nutrient uptake.', tag: 'Water Management', tag_class: 'cultural' },
                ],
            };
        }
    }
    if (key === 'blb') {
        return {
            chemical: [
                { name: 'Streptomycin Sulfate + Oxytetracycline', desc: 'Apply 150–200 ppm (1.5–2.0 g/L) foliar spray at first sign of disease.', tag: 'Bactericide', tag_class: 'bactericide' },
            ],
            organic: [
                { name: 'Field Drainage & Resistant Varieties', desc: 'Drain field for 2–3 days; plant varieties with Xa4, Xa7, or Xa21 resistance genes.', tag: 'Cultural', tag_class: 'cultural' },
            ],
        };
    }
    if (key === 'tungro') {
        return {
            chemical: [
                { name: 'Insecticide (Imidacloprid / Dinotefuran)', desc: 'Apply 0.5–1.0 ml/L to control green leafhopper vectors.', tag: 'Insecticide', tag_class: 'bactericide' },
            ],
            organic: [
                { name: 'Synchronous Planting & Rogueing', desc: 'Plant within 2-week community window and uproot severely stunted plants.', tag: 'Cultural', tag_class: 'cultural' },
            ],
        };
    }
    return {
        chemical: [{ name: 'Preventive Maintenance', desc: 'Regular mild fungicide spraying during high-risk periods.', tag: 'Fungicide', tag_class: 'fungicide' }],
        organic: [{ name: 'Good Agricultural Practices', desc: 'Maintain proper spacing, balanced fertilization, field sanitation.', tag: 'Cultural', tag_class: 'cultural' }],
    };
}
