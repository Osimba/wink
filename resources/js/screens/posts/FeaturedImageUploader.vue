<script type="text/ecmascript">
    import _ from 'lodash';
    import FeaturedImagePrepModal from './../../components/FeaturedImagePrepModal';

    export default {
        components: {
            'featured-image-prep-modal': FeaturedImagePrepModal,
        },

        props: ['postId', 'current'],

        data() {
            return {
                image: this.blankImage(),
                imagePickerKey: '',
                uploading: false,

                modalShown: false,

                // The original being cropped: {source, preview_url}.
                prepSource: null,
                prepCrop: null,
                prepGrade: Wink.featured_image.grade,
            }
        },


        mounted() {
            this.$parent.$on('openingFeaturedImageUploader', data => {
                this.image = Object.assign(this.blankImage(), _.cloneDeep(this.current));

                this.modalShown = true;
            });
        },


        methods: {
            blankImage() {
                return {
                    url: null,
                    caption: '',
                    original: null,
                    source_url: null,
                    credit: '',
                    alt: '',
                    meta: null,
                };
            },


            /**
             * Save the image.
             */
            saveImage() {
                this.$emit('changed', _.cloneDeep(this.image));

                this.close();
            },

            /**
             *  Remove image
             */
            removeImage() {
                this.image = this.blankImage();
                this.$emit('removed');
            },


            /**
             * Close the modal.
             */
            close() {
                this.imagePickerKey = _.uniqueId();

                this.modalShown = false;
            },


            /**
             * Store a picked file or URL as the original, then open the cropper.
             */
            storeSource({file, url, sourceUrl, credit}) {
                let data = new FormData();

                if (file) {
                    data.append('image', file, file.name);
                } else {
                    data.append('url', url);
                }

                this.uploading = true;

                this.http().post('/api/featured-images/sources', data).then(response => {
                    this.uploading = false;

                    this.image.source_url = sourceUrl || response.data.source_url;
                    this.image.credit = credit || '';

                    this.openPrep({source: response.data.source, preview_url: response.data.preview_url}, null, Wink.featured_image.grade);
                }).catch(error => {
                    this.uploading = false;
                    this.imagePickerKey = _.uniqueId();

                    let errors = _.get(error, 'response.data.errors', {});

                    this.alertError(_.get(errors, 'url[0]') || _.get(errors, 'image[0]') || 'The image could not be used.');
                });
            },


            /**
             * Re-crop the current image from its stored original.
             */
            recrop() {
                let meta = this.image.meta || {};

                this.openPrep({
                    source: this.image.original,
                    preview_url: '/' + Wink.path + '/api/featured-images/sources/' + this.image.original,
                }, meta.crop || null, meta.graded !== undefined ? meta.graded : Wink.featured_image.grade);
            },


            openPrep(source, crop, grade) {
                this.prepSource = source;
                this.prepCrop = crop;
                this.prepGrade = grade;
            },


            /**
             * Use the rendered image.
             */
            prepSaved({url, original, meta}) {
                this.image.url = url;
                this.image.original = original;
                this.image.meta = meta;

                this.prepSource = null;
            },
        }
    }
</script>

<template>
    <div>
        <modal v-if="modalShown && !prepSource" @close="close">
            <h2 class="font-semibold mb-5">Featured Image</h2>

            <preloader v-if="uploading"></preloader>

            <div v-if="image.url && !uploading">
                <div class="relative">
                    <button @click="removeImage"
                            class="btn-sm bg-red absolute pin-t pin-r border-black rounded mr-1 mt-1 h-6 w-8 bg-very-light">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    <img :src="image.url" class="max-w-full">
                </div>

                <button v-if="image.original" class="btn-sm btn-light mt-2" @click="recrop">Re-crop</button>

                <div class="input-group">
                    <label class="input-label">Alt text</label>
                    <input type="text" v-model="image.alt" class="input" placeholder="Describe the image (leave blank to use the post title)">
                </div>

                <div class="input-group">
                    <label class="input-label">Caption</label>
                    <textarea rows="2" v-model="image.caption" class="input" placeholder="Add caption to the image"></textarea>
                </div>

                <div class="input-group">
                    <label class="input-label">Credit</label>
                    <input type="text" v-model="image.credit" class="input" placeholder="Photo by … on Pexels">
                </div>

                <div class="input-group">
                    <label class="input-label">Source URL</label>
                    <input type="url" v-model="image.source_url" class="input" placeholder="Where the image came from">
                </div>
            </div>

            <image-picker v-if="!uploading"
                          :key="imagePickerKey"
                          prepare
                          class="mt-5"
                          @source="storeSource"></image-picker>

            <button class="btn-sm btn-primary mt-10" @click="saveImage" :disabled="uploading">Save Image</button>
            <button class="btn-sm btn-light mt-10" @click="close">Cancel</button>
        </modal>

        <featured-image-prep-modal v-if="prepSource"
                                   :source="prepSource"
                                   :crop="prepCrop"
                                   :grade="prepGrade"
                                   @saved="prepSaved"
                                   @cancel="prepSource = null"></featured-image-prep-modal>
    </div>
</template>
