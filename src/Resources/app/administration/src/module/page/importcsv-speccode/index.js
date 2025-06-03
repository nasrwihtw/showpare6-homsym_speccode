import template from './importcsv-speccode.html.twig';
import './importcsv-speccode.scss';

const { Mixin } = Shopware;

export default {
    template,

    mixins: [
        Mixin.getByName('notification')
    ],

    inject: [
        'repositoryFactory',
        'acl',
        'notification'
    ],

    data() {
        return {
            file: null,
            parsedData: [],
            isLoading: false,
            progress: 0,
            inValidBackendRows: [],
            totalInvalidRowsCount: 0,
            myArray: [
                { name: 'Alice', age: 25 },
                { name: 'Bob', age: 30 }
            ]
        };
    },

    methods: {
        onFileChange(event) {
            this.file = event.target.files[0];
        },

        async onSubmit() {
            if (!this.file) {
                this.createNotificationError({
                    message: this.$tc('speccode.importcsv-speccode.noFile')
                });
                return;
            }

            try {
                const text = await this.file.text();
                const lines = text.split(/\r?\n/).filter(line => line.trim() !== '');
                const headers = this.parseCSVLine(lines[0]);

                const csvData = lines.slice(1).map(line => {
                    const values = this.parseCSVLine(line);
                    const obj = {};
                    headers.forEach((header, i) => {
                        obj[header] = values[i] ? values[i].trim() : '';
                    });
                    return obj;
                });

                this.parsedData = csvData;

                this.isLoading = true;
                this.progress = 0;

                const response = await Shopware.Service('syncService')
                    .httpClient.post('homsymimportcsvspecode/setspeccode', this.parsedData);

                if(response?.data?.failedItems?.length > 0){
                    this.handleInvalidRows(response.data.failedItems);
                }

                this.progress = 100;


                this.createNotificationSuccess({
                    message: this.$tc('speccode.importcsv-speccode.successUpload', this.parsedData.length)
                });

            } catch (error) {
                console.error(error);
                this.createNotificationError({
                    message: this.$tc('speccode.importcsv-speccode.errorUpload')
                });
            } finally {
                this.isLoading = false;
                this.progress = 100;
            }
        },

        parseCSVLine(line) {
            const regex = /(?:\"([^\"]*(?:\"\"[^\"]*)*)\"|([^",;]+))(?:,|;)?/g;
            const result = [];
            let match;
            while ((match = regex.exec(line))) {
                result.push(match[1] ? match[1].replace(/\"\"/g, '"') : match[2]);
            }
            return result;
        },

         handleInvalidRows(newInvalidRows) {
             this.inValidBackendRows.push(...newInvalidRows);
             this.totalInvalidRowsCount +=newInvalidRows.length;
         },

        saveInvalidRowsToFile(){
            const dataStr = this.inValidBackendRows.map(item => {
                return Object.values(item).join(', ');
            }).join('\n');
            const blob = new Blob([dataStr], { type: 'text/plain'  });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'NotFoundProdukteUndKategorien.txt';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);

        }


    }
};
