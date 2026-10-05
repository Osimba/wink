<script type="text/ecmascript">
    import _ from 'lodash';
    import axios from 'axios';

    export default {
        props: {
            // Hand the chosen file or URL to the parent instead of uploading it.
            prepare: {type: Boolean, default: false},
        },

        data() {
            return {
                file: null,
                imageUrl: '',
                uploadProgress: 100,

                selectedUnsplashImage: null,
                unsplashModalShown: false,
                unsplashSearchTerm: '',
                unsplashPage: 1,
                searchingUnsplash: true,
                unsplashImages: [],

                cropperModalShown: false,

                urlFormShown: false,
                remoteUrl: '',
                importing: false,
            }
        },


        mounted() {

        },


        watch: {
            unsplashSearchTerm() {
                this.debouncer(() => {
                    this.getImagesFromUnsplash();
                });
            }
        },


        methods: {
            getImagesFromUnsplash(page = 1) {
                if (!Wink.unsplash_key) {
                    return this.alertError('Please configure your Unsplash API Key.');
                }

                this.unsplashPage = page;

                this.searchingUnsplash = true;

                axios.get('https://api.unsplash.com/search/photos?client_id=' + Wink.unsplash_key +
                    '&orientation=landscape&per_page=19' +
                    '&query=' + this.unsplashSearchTerm +
                    '&page=' + page
                ).then(response => {
                    this.unsplashImages = response.data.results;

                    this.searchingUnsplash = false;
                }).catch(error => {
                    let errors = error.response.data.errors;

                    this.searchingUnsplash = false;
                });
            },


            /**
             * Load the selected image into the Cropper.
             */
            loadSelectedImage(event){
                this.file = event.target.files[0];

                if (this.prepare) {
                    return this.$emit('source', {file: this.file});
                }

                this.showCropperModal();
            },


            /**
             * Show the URL field.
             */
            showUrlForm() {
                this.urlFormShown = true;

                this.$nextTick(() => {
                    this.$refs.remoteUrl.focus();
                });
            },


            /**
             * Import the image at the pasted URL.
             */
            importFromUrl() {
                let url = this.remoteUrl.trim();

                if (!url) {
                    return;
                }

                if (this.prepare) {
                    return this.$emit('source', {url});
                }

                this.importing = true;
                this.$emit('uploading');

                this.http().post('/api/uploads/from-url', {url}).then(response => {
                    this.importing = false;
                    this.$emit('changed', {url: response.data.url, caption: ''});
                }).catch(error => {
                    this.importing = false;
                    this.$emit('failed');
                    this.alertError(_.get(error, 'response.data.errors.url[0]', 'The image could not be imported.'));
                });
            },

            /**
             * Open unsplash modal.
             */
            openUnsplashModal() {
                this.unsplashSearchTerm = 'sunny';
                this.unsplashModalShown = true;

                this.$nextTick(() => {
                    this.$refs.unsplashSearch.focus();
                })
            },


            /**
             * Select an unsplash Image.
             */
            closeUnplashModalAndInsertImage() {
                if (this.prepare) {
                    let image = this.selectedUnsplashImage;

                    this.closeUnsplashModal();

                    return this.$emit('source', {
                        url: image.urls.raw + '&w=2400&fm=jpg&q=85',
                        sourceUrl: image.links.html,
                        credit: 'Photo by ' + image.user.name + ' on Unsplash',
                    });
                }

                this.$emit('changed', {
                    url: this.selectedUnsplashImage.urls.regular,
                    caption: 'Photo by <a href="' + this.selectedUnsplashImage.user.links.html + '">' + this.selectedUnsplashImage.user.name + '</a> on <a href="https://unsplash.com">Unsplash</a>',
                });

                this.closeUnsplashModal();
            },


            /**
             * Close unsplash modal.
             */
            closeUnsplashModal() {
                this.unsplashSearchTerm = '';
                this.unsplashModalShown = false;
                this.selectedUnsplashImage = null;
            },


            /**
             * Open the cropper modal.
             */
            showCropperModal() {
                this.cropperModalShown = true;
            },


            /**
             * Close the cropper modal.
             */
            closeCropperModal({image}) {
                this.cropperModalShown = false;
                this.imageUrl = image;
                this.$emit('changed', {url: image, caption: ''});
            },


            /**
             * Close and Cancel the cropper modal.
             */
            cancelCropperModal() {
                this.cropperModalShown = false;
            }
        }
    }
</script>

<template>
    <div>
        <input type="file" class="hidden" :id="'imageUpload'+_uid" accept="image/*" v-on:change="loadSelectedImage">

        <div class="mb-0">
            Please <label :for="'imageUpload'+_uid" class="cursor-pointer underline">upload</label> an image<span v-if="Wink.remote_images && Wink.unsplash_key">,</span>
            <span v-if="Wink.remote_images && !Wink.unsplash_key">or</span>
            <a v-if="Wink.remote_images" href="#" @click.prevent="showUrlForm" class="text-text-color">paste a URL</a>
            <span v-if="Wink.unsplash_key">or</span>
            <a v-if="Wink.unsplash_key" href="#" @click.prevent="openUnsplashModal" class="text-text-color">search Unsplash</a>
        </div>

        <form v-if="urlFormShown" class="flex items-center mt-4" @submit.prevent="importFromUrl">
            <input type="url" class="input mr-2"
                   ref="remoteUrl"
                   v-model="remoteUrl"
                   :disabled="importing"
                   placeholder="https://example.com/photo.jpg">
            <button type="submit" class="btn-sm btn-primary" :disabled="importing || !remoteUrl">Import</button>
        </form>

        <fullscreen-modal v-if="unsplashModalShown">
            <div class="bg-contrast z-50 fixed pin overflow-y-scroll">
                <div class="container py-20">
                    <div class="flex items-center">
                        <h2 class="mr-auto">Search Unsplash</h2>

                        <button class="btn-primary mr-4" v-if="selectedUnsplashImage" @click="closeUnplashModalAndInsertImage">Choose Selected Image</button>
                        <button class="btn-light" @click="closeUnsplashModal">Cancel</button>
                    </div>

                    <input type="text" class="my-10 border-b border-very-light focus:outline-none w-full"
                           v-if="Wink.unsplash_key"
                           v-model="unsplashSearchTerm"
                           ref="unsplashSearch"
                           placeholder="search Unsplash">

                    <preloader v-if="searchingUnsplash" class="mt-10"></preloader>

                    <div v-if="!searchingUnsplash && unsplashImages.length" class="flex flex-wrap mt-5">
                        <div class="w-1/4 p-1 cursor-pointer" v-for="image in unsplashImages" @click="selectedUnsplashImage = image">
                            <div class="h-48 w-full bg-cover border-primary"
                                 :class="{'border-4': selectedUnsplashImage && selectedUnsplashImage.id == image.id}" :style="{ backgroundImage: 'url(' + image.urls.thumb + ')' }"></div>
                        </div>

                        <div class="w-1/4 p-1" v-if="unsplashImages.length == 19">
                            <div class="bg-primary text-center flex items-center justify-center h-full">
                                <button class="text-contrast hover:underline" @click="getImagesFromUnsplash(unsplashPage + 1)">More >></button>
                            </div>
                        </div>
                    </div>

                    <div v-if="!searchingUnsplash && !unsplashImages.length">
                        <h4 class="text-center">We couldn't find any matches.</h4>
                    </div>
                </div>
            </div>
        </fullscreen-modal>

        <cropper-modal v-if="cropperModalShown"
                       :image="file"
                       :viewport ="{ width: 600, height: 400 }"
                       :boundary="{ width: 600, height: 400 }"
                       @close="closeCropperModal"
                       @cancel="cancelCropperModal"></cropper-modal>
    </div>
</template>
