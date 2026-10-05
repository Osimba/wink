<script type="text/ecmascript">
    export default {
        props: ['postId'],

        data() {
            return {
                existingBlot: null,
                imageUrl: null,
                layout: 'default',
                caption: '',
                altText: '',
                imagePickerKey: '',
                uploadProgress: 0,
                uploading: false,

                modalShown: false,
            }
        },

        mounted() {
            this.layouts = [{id: 'test', name: 'Test'}];

            this.$parent.$on('openingImageUploader', data => {
                if (data) {
                    this.caption = data.caption;
                    this.altText = data.alt || '';
                    this.imageUrl = data.url;
                    this.layout = data.layout || 'default';
                    this.existingBlot = data.existingBlot;
                }

                this.modalShown = true;
            });
        },


        methods: {
            close() {
                this.modalShown = false;

                this.imagePickerKey = _.uniqueId();

                this.existingBlot = null;

                this.imageUrl = null;

                this.layout = 'default';

                this.caption = '';

                this.altText = '';
            },


            updateImage({url, caption}) {
                this.imageUrl = url;
                this.caption = caption;

                this.uploading = false;
            },


            applyImage() {
                if (!this.imageUrl) {
                    return this.alertError('Please select an image.');
                }

                this.$emit('updated', {
                    url: this.imageUrl,
                    caption: this.caption,
                    alt: this.altText,
                    existingBlot: this.existingBlot,
                    layout: this.layout,
                });

                this.close();
            },


            updateProgress({progress}) {
                this.uploadProgress = progress;
            }
        }
    }
</script>

<template>
    <modal v-if="modalShown" @close="close">
        <h2 class="font-semibold mb-5">Add Image</h2>

        <preloader v-if="uploading"></preloader>

        <div v-if="imageUrl && !uploading">
            <img :src="imageUrl" class="max-w-full">

            <div class="input-group">
                <label class="input-label">Caption</label>
                <textarea rows="2" v-model="caption" ref="caption" class="input" placeholder="Add caption to the image"></textarea>
            </div>

            <div class="input-group">
                <label class="input-label">Alt text</label>
                <textarea rows="2" v-model="altText" class="input" placeholder="Describe the image for screen readers and search engines"></textarea>
                <p class="text-xs mt-1">Sets the image's <code>alt</code> attribute (accessibility &amp; SEO). Leave blank to reuse the caption.</p>
            </div>

            <div class="input-group">
                <label class="input-label">Layout</label>
                <select class="input" v-model="layout">
                    <option value="default">Default</option>
                    <option value="wide">Wide Image</option>
                </select>
            </div>
        </div>

        <image-picker v-if="!imageUrl" :key="imagePickerKey"
                      class="mt-5"
                      @changed="updateImage"
                      @progressing="updateProgress"
                      @uploading="uploading = true"
                      @failed="uploading = false"></image-picker>

        <button class="btn-sm btn-primary mt-10" @click="applyImage">Apply</button>
        <button class="btn-sm btn-light mt-10" @click="close">Cancel</button>
    </modal>
</template>

<style>

</style>
