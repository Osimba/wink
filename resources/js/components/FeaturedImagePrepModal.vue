<script type="text/ecmascript">
    import _ from 'lodash';
    import Cropper from 'cropperjs';
    import smartcrop from 'smartcrop';
    import 'cropperjs/dist/cropper.css';

    export default {
        props: {
            // A stored original: {source, preview_url}.
            source: {type: Object, required: true},

            // A previously saved crop box, when re-cropping.
            crop: {type: Object, default: null},

            grade: {type: Boolean, default: true},
        },


        data() {
            return {
                cropper: null,
                box: null,
                applyGrade: this.grade,

                preview: null,
                previewing: false,
                saving: false,

                width: Wink.featured_image.width,
                height: Wink.featured_image.height,
            }
        },


        computed: {
            /**
             * Whether the crop is narrower than the output and will be upscaled.
             */
            upscaled() {
                return this.box && this.box.width < this.width;
            },
        },


        mounted() {
            this.refreshPreview = _.debounce(this.refreshPreview, 500);
        },


        beforeDestroy() {
            if (this.cropper) {
                this.cropper.destroy();
            }
        },


        methods: {
            /**
             * Start the cropper on the suggested crop once the original loads.
             */
            imageLoaded() {
                let image = this.$refs.image;

                this.suggestCrop(image).then(box => {
                    this.cropper = new Cropper(image, {
                        aspectRatio: this.width / this.height,
                        viewMode: 1,
                        autoCropArea: 1,
                        movable: false,
                        rotatable: false,
                        scalable: false,
                        zoomable: false,
                        checkOrientation: false,
                        data: box,
                        crop: () => this.cropChanged(),
                    });
                });
            },


            /**
             * The saved crop, else smartcrop's suggestion, else the default.
             */
            suggestCrop(image) {
                if (this.crop) {
                    return Promise.resolve(this.crop);
                }

                return smartcrop.crop(image, {width: this.width, height: this.height, minScale: 1})
                    .then(result => {
                        let {x, y, width, height} = result.topCrop;

                        return {x, y, width, height};
                    })
                    .catch(() => this.defaultCrop(image.naturalWidth, image.naturalHeight));
            },


            /**
             * Centre horizontally; start 40% of the way down the spare height.
             */
            defaultCrop(imageWidth, imageHeight) {
                let ratio = this.width / this.height;

                if (imageWidth / imageHeight > ratio) {
                    let width = Math.floor(imageHeight * ratio);

                    return {x: Math.floor((imageWidth - width) / 2), y: 0, width, height: imageHeight};
                }

                let height = Math.floor(imageWidth / ratio);

                return {x: 0, y: Math.floor((imageHeight - height) * 0.4), width: imageWidth, height};
            },


            /**
             * Track the crop box in the original's pixels.
             */
            cropChanged() {
                let {x, y, width, height} = this.cropper.getData(true);

                this.box = {x: Math.max(0, x), y: Math.max(0, y), width, height};

                this.refreshPreview();
            },


            /**
             * Fetch small before and after renders of the current crop.
             */
            refreshPreview() {
                if (!this.box) return;

                let box = this.box;

                this.previewing = true;

                this.http().post('/api/featured-images/preview', {source: this.source.source, crop: box}).then(response => {
                    if (box === this.box) {
                        this.preview = response.data;
                        this.previewing = false;
                    }
                }).catch(() => {
                    this.previewing = false;
                });
            },


            /**
             * Render and store the featured image.
             */
            save() {
                this.saving = true;

                this.http().post('/api/featured-images', {
                    source: this.source.source,
                    crop: this.box,
                    grade: this.applyGrade,
                }).then(response => {
                    this.saving = false;
                    this.$emit('saved', response.data);
                }).catch(error => {
                    this.saving = false;
                    this.alertError(_.get(error, 'response.data.message', 'The image could not be saved.'));
                });
            },


            cancel() {
                this.$emit('cancel');
            },
        }
    }
</script>

<template>
    <fullscreen-modal>
        <div class="bg-contrast z-50 fixed pin overflow-y-scroll">
            <div class="container py-20">
                <div class="flex items-center mb-8">
                    <h2 class="mr-auto">Prepare Featured Image</h2>

                    <button class="btn-primary mr-4" @click="save" :disabled="!box || saving">
                        {{ saving ? 'Saving…' : 'Use This Crop' }}
                    </button>
                    <button class="btn-light" @click="cancel" :disabled="saving">Cancel</button>
                </div>

                <div style="height: 60vh">
                    <img ref="image" :src="source.preview_url" class="block max-w-full" @load="imageLoaded">
                </div>

                <p class="mt-4 text-light text-sm">
                    Drag the box to choose what shows. The image is cropped to {{ width }}×{{ height }}.
                    <span v-if="upscaled" class="text-red">
                        This crop is only {{ box.width }}px wide, so it will be enlarged and may look soft.
                    </span>
                </p>

                <div class="input-group">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" v-model="applyGrade" class="mr-2">
                        <span class="font-bold">Apply house grade</span>
                    </label>
                </div>

                <div class="flex mt-6 -mx-2">
                    <div class="w-1/2 px-2">
                        <div class="text-sm font-bold mb-2">Before</div>
                        <img v-if="preview" :src="preview.original" class="block w-full" :class="{'opacity-50': applyGrade}">
                    </div>
                    <div class="w-1/2 px-2">
                        <div class="text-sm font-bold mb-2">After house grade</div>
                        <img v-if="preview" :src="preview.graded" class="block w-full" :class="{'opacity-50': !applyGrade}">
                    </div>
                </div>

                <preloader v-if="previewing && !preview" class="mt-6"></preloader>
            </div>
        </div>
    </fullscreen-modal>
</template>
