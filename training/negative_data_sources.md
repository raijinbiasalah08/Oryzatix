# Negative training image sources

## Local hard negatives

Put varied, clearly non-rice-leaf images under `Not Rice Leaf/people`, `Not Rice Leaf/objects`, and `Not Rice Leaf/other_plants`. These files are intentionally ignored by Git because raw training images should stay local; the trained model and class labels are what get packaged for the app.

The starting hard negatives in this workspace are cropped from scan-result images supplied by the project owner. Add more varied people, household objects, and scene photos before treating the negative class as broadly reliable.

## Other plant leaves

The apple-leaf subset is from Derya Birant's [PlantVillage Dataset](https://doi.org/10.6084/m9.figshare.26096800), released under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Cite the dataset author and source when redistributing or publishing results. It contains healthy and diseased apple leaves captured under controlled conditions; add field images from multiple non-rice crops to reduce the lab-to-field gap.

Do not label rice leaves as negative examples, including diseased rice leaves. Include full-image negatives (people, objects, and scenes) as well as close-up leaves from non-rice plants.
