# Negative training image sources

## Local hard negatives

Put varied, clearly non-rice-leaf images under `Not Rice Leaf/people`, `Not Rice Leaf/objects`, and `Not Rice Leaf/other_plants`. These files are intentionally ignored by Git because raw training images should stay local; the trained model and class labels are what get packaged for the app.

The current training pool has 3,167 non-rice images: 3,164 apple leaves, 2 people images, and 1 bookstore scene. One person image and the bookstore scene are included in training; the other person image is held out for validation. The object-scene result is only a training-fit check, and one held-out person photo cannot establish general rejection of people. Add varied, labeled photos of people, indoor and outdoor objects, landscapes, and non-rice crops. Keep a separate scene-level test set that is not used to select thresholds.

## Other plant leaves

The apple-leaf subset is from Derya Birant's [PlantVillage Dataset](https://doi.org/10.6084/m9.figshare.26096800), released under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Cite the dataset author and source when redistributing or publishing results. It contains healthy and diseased apple leaves captured under controlled conditions; add field images from multiple non-rice crops to reduce the lab-to-field gap.

Do not label rice leaves as negative examples, including diseased rice leaves. Include full-image negatives (people, objects, and scenes) as well as close-up leaves from non-rice plants. The current blur threshold was measured using synthetic Gaussian blur of rice validation images; add genuinely blurry photos with labels to measure real-photo blur behavior.
